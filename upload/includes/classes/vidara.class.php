<?php
/**
 * Vidara.so video hosting.
 *
 * Uploaded videos are sent to Vidara instead of being converted and stored on
 * the local disk. The ClipBucket player stays untouched: the video record keeps
 * a "vidara:<filecode>" marker in remote_play_url and the existing player asks
 * Vidara for the current stream URL when the watch page is rendered.
 */
class Vidara
{
    const MARKER = 'vidara:';
    const API_BASE = 'https://api.vidara.so/v1/';
    const PLAYER_STREAM_API = 'https://vidara.to/api/stream';

    private static $stream_urls = [];

    public static function isEnabled(): bool
    {
        return self::getApiKey() !== '';
    }

    public static function getApiKey(): string
    {
        return trim((string)getenv('VIDARA_API_KEY'));
    }

    public static function isVidaraVideo($video): bool
    {
        return !empty($video['remote_play_url']) && strpos($video['remote_play_url'], self::MARKER) === 0;
    }

    public static function getFilecode($video): string
    {
        return self::isVidaraVideo($video) ? substr($video['remote_play_url'], strlen(self::MARKER)) : '';
    }

    /**
     * @throws Exception
     */
    private static function request(string $url, $post_fields = null, $json_body = null, int $timeout = 60): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
        ];
        if ($json_body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $json_body;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        } elseif ($post_fields !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $post_fields;
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Exception('Vidara request failed: ' . $error);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception('Vidara returned an unexpected response');
        }
        return $data;
    }

    /**
     * @throws Exception
     */
    public static function getUploadServer(): string
    {
        $data = self::request(self::API_BASE . 'upload/server?api_key=' . urlencode(self::getApiKey()));
        return $data['result']['upload_server'] ?? 'https://s1.vidara.so/api/upload';
    }

    /**
     * @throws Exception
     */
    public static function uploadFile(string $file_path): string
    {
        $data = self::request(self::getUploadServer(), [
            'api_key' => self::getApiKey(),
            'file'    => new CURLFile($file_path),
        ], null, 7200);

        $filecode = $data['filecode'] ?? '';
        if (empty($filecode)) {
            throw new Exception('Vidara upload failed: ' . json_encode($data));
        }
        // The API answers with either a bare code or an embed URL.
        if (strpos($filecode, '/') !== false) {
            $filecode = basename($filecode);
        }
        return $filecode;
    }

    /**
     * Current playable stream URL (tokenized, short lived).
     *
     * @throws Exception
     */
    public static function getStreamUrl(string $filecode): string
    {
        if (array_key_exists($filecode, self::$stream_urls)) {
            return self::$stream_urls[$filecode];
        }

        $data = self::request(self::PLAYER_STREAM_API, null, json_encode([
            'filecode' => $filecode,
            'device'   => 'desktop',
            'codecs'   => ['h264' => true, 'h265' => false, 'aac' => true],
        ]), 10);

        self::$stream_urls[$filecode] = $data['streaming_url'] ?? '';
        return self::$stream_urls[$filecode];
    }

    /**
     * Replaces local conversion: send the uploaded file to Vidara and finalize
     * the video record.
     */
    public static function process(string $file_name): bool
    {
        $video = CBvideo::getInstance()->get_video($file_name, true);
        if (empty($video)) {
            return false;
        }

        $log = new SLog(DirPath::get('logs') . $video['file_directory'] . DIRECTORY_SEPARATOR . $file_name . '.log');
        $log->newSection('Vidara upload');

        $temp_file = DirPath::get('temp') . $file_name . '.' . $video['file_type'];
        if (!file_exists($temp_file)) {
            $log->writeLine(date('Y-m-d H:i:s') . ' - Uploaded file not found: ' . $temp_file);
            setVideoStatus($video['videoid'], 'Failed');
            return false;
        }

        try {
            $filecode = self::uploadFile($temp_file);
            $log->writeLine(date('Y-m-d H:i:s') . ' - Stored on Vidara: ' . $filecode);
        } catch (Throwable $e) {
            $log->writeLine(date('Y-m-d H:i:s') . ' - Vidara upload failed: ' . $e->getMessage());
            setVideoStatus($video['videoid'], 'Failed');
            return false;
        }

        // Build the thumbnails from the local copy, before it is deleted.
        $ffmpeg = new FFMpeg($log);
        $info = $ffmpeg->get_file_info($temp_file);
        $ffmpeg->input_file = $temp_file;
        $ffmpeg->file_name = $file_name;
        $ffmpeg->file_directory = $video['file_directory'] . DIRECTORY_SEPARATOR;
        $ffmpeg->input_details['duration'] = $info['duration'] ?? 0;
        $ffmpeg->input_details['video_width'] = $info['video_width'] ?? '';
        $ffmpeg->input_details['video_height'] = $info['video_height'] ?? '';
        (new VideoThumbs($video['videoid'], $ffmpeg))->generateAutomaticThumbs();

        $fields = [
            'status'           => 'Successful',
            'file_type'        => 'hls',
            'remote_play_url'  => self::MARKER . $filecode,
            'duration'         => (int)($info['duration'] ?? 0),
            'convert_percent'  => 100,
            'active'           => config('activation') ? 'no' : 'yes',
        ];
        Clipbucket_db::getInstance()->update(tbl('video'), array_keys($fields), array_values($fields), 'videoid = ' . (int)$video['videoid']);

        $queue = VideoConversionQueue::getOne(['videoid' => $video['videoid'], 'not_complete' => true]);
        if (!empty($queue)) {
            Clipbucket_db::getInstance()->update(tbl(VideoConversionQueue::getTableName()), ['date_ended', 'is_completed'], ['|no_mc||f|NOW()', true], 'id = ' . (int)$queue['id']);
        }

        unlink($temp_file);
        $log->writeLine(date('Y-m-d H:i:s') . ' - Conversion completed, local copy removed');

        return true;
    }
}

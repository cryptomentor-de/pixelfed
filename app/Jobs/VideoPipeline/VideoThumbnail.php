<?php

namespace App\Jobs\VideoPipeline;

use App\Jobs\MediaPipeline\MediaStoragePipeline;
use App\Media;
use App\Services\MediaService;
use App\Services\StatusService;
use App\Util\Media\Blurhash;
use Cache;
use FFMpeg;
use Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class VideoThumbnail implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $media;

    public $timeout = 900;

    public $tries = 3;

    public $maxExceptions = 1;

    public $failOnTimeout = true;

    public $deleteWhenMissingModels = true;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 3600;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'media:video-thumb:id-'.$this->media->id;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("media:video-thumb:id-{$this->media->id}"))->shared()->dontRelease()];
    }

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Media $media)
    {
        $this->media = $media;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $media = $this->media;
        if (!in_array($media->mime, ['video/mp4', 'video/quicktime'])) {
            return;
        }
        $base = $media->media_path;
        $path = explode('/', $base);
        $name = last($path);
        try {
            $t = explode('.', $name);
            $t = $t[0].'_thumb.jpeg';
            $i = count($path) - 1;
            $path[$i] = $t;
            $save = implode('/', $path);
            $video = FFMpeg::open($base)
                ->getFrameFromSeconds(1)
                ->export()
                ->toDisk('local')
                ->save($save);

            $media->thumbnail_path = $save;
            $media->save();

            $blurhash = Blurhash::generate($media);
            if ($blurhash) {
                $media->blurhash = $blurhash;
                $media->save();
            }

            if (config('media.hls.enabled')) {
                VideoHlsPipeline::dispatch($media)->onQueue('mmo');
            }

            // Upload thumbnail to S3 and set thumbnail_url when cloud storage is active.
            // VideoThumbnail only saves to local disk; VideoThumbnailToCloudPipeline
            // handles the S3 upload and will reuse the local copy without re-extracting.
            if ((bool) config_cache('pixelfed.cloud_storage')) {
                VideoThumbnailToCloudPipeline::dispatch($media)->onQueue('mmo');
            }
        } catch (\Exception $e) {
            if (config('app.dev_log')) {
                Log::error('Video thumbnail generation failed: '.$e->getMessage());
            }

            throw $e;
        }

        // Re-mux with faststart before S3 upload.
        // Moves moov atom to front of file so iOS can begin playback without
        // downloading the entire video first (progressive streaming).
        // For video/quicktime (iOS uploads): also converts MOV container → MP4 and
        // renames the local file + DB record so MediaStoragePipeline uploads with
        // correct container and mime type.
        try {
            $videoPath = storage_path('app/'.$base);
            if (file_exists($videoPath)) {
                $tmpPath = $videoPath.'.remux.mp4';
                $ffmpegBin = config('laravel-ffmpeg.ffmpeg.binaries', '/usr/bin/ffmpeg');
                shell_exec(escapeshellarg($ffmpegBin).' -i '.escapeshellarg($videoPath)
                    .' -c copy -movflags +faststart '.escapeshellarg($tmpPath).' 2>/dev/null');
                if (file_exists($tmpPath) && filesize($tmpPath) > 1024) {
                    rename($tmpPath, $videoPath);
                    if ($media->mime === 'video/quicktime') {
                        $mp4Path = preg_replace('/\.[^.]+$/', '.mp4', $videoPath);
                        if (rename($videoPath, $mp4Path)) {
                            $media->media_path = preg_replace('/\.[^.]+$/', '.mp4', $base);
                            $media->mime = 'video/mp4';
                            $media->save();
                        }
                    }
                } else {
                    @unlink($tmpPath);
                }
            }
        } catch (\Exception $e) {
            // non-fatal — original file will be uploaded without faststart
        }

        if ($media->status_id) {
            Cache::forget('status:transformer:media:attachments:'.$media->status_id);
            MediaService::del($media->status_id);
            Cache::forget('status:thumb:nsfw0'.$media->status_id);
            Cache::forget('status:thumb:nsfw1'.$media->status_id);
            Cache::forget('pf:services:sh:id:'.$media->status_id);
            StatusService::del($media->status_id);
        }

        MediaStoragePipeline::dispatch($media);
    }
}

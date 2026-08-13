<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class FileTransferService
{
    protected $localDisk;
    protected $ftpDisk;
    protected FileNameService $fileNameService;

    public function __construct(FileNameService $fileNameService)
    {
        // دیسک لوکال برای نگهداری موقت فایل‌ها قبل از انتقال
        $this->localDisk = Storage::disk('local');
        // دیسک FTP که در مرحله قبل ساختیم
        $this->ftpDisk = Storage::disk('remote_ftp');
        $this->fileNameService = $fileNameService;
    }

    /**
     * ذخیره یک تکه (Chunk) فایل روی سرور لاراول
     */
    public function storeChunk($uploadId, $chunkIndex, $file)
    {
        $tempPath = "temp_uploads/{$uploadId}";

        // اطمینان از وجود پوشه
        if (!$this->localDisk->exists($tempPath)) {
            $this->localDisk->makeDirectory($tempPath);
        }

        // ذخیره تکه فایل
        $chunkPath = "{$tempPath}/chunk_{$chunkIndex}";
        $this->localDisk->put($chunkPath, file_get_contents($file));

        return $chunkPath;
    }

    /**
     * بررسی وضعیت آپلود (تعداد تکه‌های دریافت شده)
     */
    public function checkUploadStatus(
        string $uploadId,
        int $totalChunks
    ): array {
        $tempPath = "temp_uploads/{$uploadId}";

        if (!$this->localDisk->exists($tempPath)) {
            return [
                'received_count' => 0,
                'missing_chunks' => range(0, $totalChunks - 1),
                'complete' => false,
            ];
        }

        $files = $this->localDisk->files($tempPath);

        $receivedIndexes = [];

        foreach ($files as $file) {
            if (preg_match('/chunk_(\d+)$/', $file, $matches)) {
                $receivedIndexes[] = (int) $matches[1];
            }
        }

        $receivedIndexes = array_values(array_unique($receivedIndexes));
        usort($files, function ($a, $b) {
            preg_match('/chunk_(\d+)$/', $a, $matchA);
            preg_match('/chunk_(\d+)$/', $b, $matchB);

            return ((int) ($matchA[1] ?? 0)) <=> ((int) ($matchB[1] ?? 0));
        });


        $expectedIndexes = range(0, $totalChunks - 1);

        $missingIndexes = array_values(array_diff(
            $expectedIndexes,
            $receivedIndexes
        ));

        return [
            'received_count' => count($receivedIndexes),
            'missing_chunks' => $missingIndexes,
            'complete' => count($missingIndexes) === 0,
        ];


//        if (!$this->localDisk->exists($tempPath)) {
//            return 0;
//        }
//
//        $files = $this->localDisk->files($tempPath);
//        return count($files);
    }

    /**
     * ادغام تکه‌ها و انتقال نهایی به FTP
     * @throws Exception
     */
    public function finalizeAndTransfer($uploadId, $totalChunks, $originalFilename, $destinationPath)
    {
        $lockHandle = $this->acquireUploadLock($uploadId);

        try {
            $tempPath = "temp_uploads/{$uploadId}";
            $status = $this->checkUploadStatus($uploadId, $totalChunks);

            if (!$status['complete']) {
                throw new Exception('تعداد تکه‌های ناقص است.');
            }

            // ۱. ساخت نام فایل امن
            $safeFilename = $this->fileNameService->normalize(
                $originalFilename,
                $destinationPath,
                $this->ftpDisk
            );

            // ۲. مسیر کامل روی سرور FTP
            // نکته: مسیر destinationPath شامل فصل/درس/پایه است که از کنترلر می‌آید
            $finalFtpPath = trim($destinationPath, '/') . '/' . $safeFilename;

            \Log::info("Final FTP Path: " . $finalFtpPath);

            // ۳. باز کردن جریان (Stream) برای نوشتن روی FTP
            // استفاده از Stream باعث می‌شود فایل کامل حافظه رم را پر نکند
//        $stream = fopen('php://temp', 'w+');

            // مسیر فایل merge شده روی دیسک
            $mergedPath = storage_path("app/{$tempPath}/merged.tmp");

            // ساخت فایل merge روی disk
            $stream = fopen($mergedPath, 'w');

            if (!$stream) {
                throw new Exception("امکان ایجاد فایل موقت برای ادغام وجود ندارد.");
            }

            // مرتب‌سازی تکه‌ها و ادغام
            for ($index = 0; $index < $totalChunks; $index++) {
                $file = "{$tempPath}/chunk_{$index}";

                if (!$this->localDisk->exists($file)) {
                    fclose($stream);
                    throw new Exception("تکه شماره {$index} یافت نشد.");
                }

                $chunkStream = $this->localDisk->readStream($file);

                if ($chunkStream === false) {
                    fclose($stream);
                    throw new Exception("خطا در خواندن تکه شماره {$index}.");
                }

                stream_copy_to_stream($chunkStream, $stream);
                fclose($chunkStream);
            }


            fclose($stream);



            // ارسال فایل merge شده به FTP
//        $readStream = fopen($mergedPath, 'r');
//
//        if (!$readStream) {
//            throw new Exception("خطا در باز کردن فایل ادغام شده.");
//        }
//
//        \Log::info("Uploading merged file to FTP", [
//            'path' => $finalFtpPath,
//            'size' => filesize($mergedPath)
//        ]);
//
//        $success = $this->ftpDisk->writeStream($finalFtpPath, $readStream);
//
//        fclose($readStream);
//
//        if (!$success) {
//            \Log::error("FTP writeStream returned false for path: " . $finalFtpPath);
//            throw new Exception("خطا در انتقال فایل به سرور FTP.");
//        }

            \Log::info("Uploading merged file to FTP", [
                'path' => $finalFtpPath,
                'size' => filesize($mergedPath)
            ]);

            $success = $this->safeWriteStreamToFtp($mergedPath, $finalFtpPath);

            if (!$success) {
                throw new Exception("خطا در انتقال فایل به FTP بعد از چند تلاش.");
            }

            $directory = dirname($finalFtpPath);

            $cacheKey = 'ftp_list_' . md5($directory);

            Cache::forget($cacheKey);

            // حذف فایل merge شده
            unlink($mergedPath);

//        // بازگرداندن نشانگر به ابتدای فایل برای خواندن
//        rewind($stream);
//
//        // ۴. انتقال به FTP
//        // متد writeStream فایل را مستقیم از استریم به FTP می‌فرستد
//        $success = $this->ftpDisk->writeStream($finalFtpPath, $stream);
//
//        // بستن استریم
//        fclose($stream);
//
//        if (!$success) {
//            \Log::error("FTP writeStream returned false for path: " . $finalFtpPath);
//            throw new Exception("خطا در انتقال فایل به سرور FTP.");
//        }

            // // ۵. پاکسازی فایل‌های موقت از سرور لاراول
            $this->localDisk->deleteDirectory($tempPath);

            return [
                'filename' => $safeFilename,
                'path' => $finalFtpPath,
                'size' => $this->ftpDisk->size($finalFtpPath)
            ];
        } finally {
            $this->releaseUploadLock($lockHandle);
        }
    }

    private function safeWriteStreamToFtp(string $localPath, string $ftpPath, int $maxAttempts = 5): bool
    {
        $attempt = 0;

        while ($attempt < $maxAttempts) {

            $attempt++;

            try {

                \Log::info('FTP upload attempt', [
                    'attempt' => $attempt,
                    'local_path' => $localPath,
                    'ftp_path' => $ftpPath,
                ]);

                if (!file_exists($localPath)) {
                    \Log::warning('Merged file missing before FTP upload', [
                        'attempt' => $attempt,
                        'local_path' => $localPath,
                        'ftp_path' => $ftpPath,
                    ]);

                    usleep(300000);
                    continue;
                }


                $stream = fopen($localPath, 'r');

                if (!$stream) {
                    throw new Exception("Cannot open local file stream.");
                }

                // گرفتن connection جدید
                $ftpDisk = Storage::disk('remote_ftp');

                $success = $ftpDisk->writeStream($ftpPath, $stream);

                fclose($stream);

                if ($success) {

                    \Log::info('FTP upload success', [
                        'attempt' => $attempt,
                        'path' => $ftpPath
                    ]);

                    return true;
                }

                \Log::warning('FTP upload failed', [
                    'attempt' => $attempt,
                    'path' => $ftpPath
                ]);

            } catch (\Throwable $e) {

                \Log::warning('FTP upload exception', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

            }

            // delay قبل از retry
            usleep(1000000);
        }

        return false;
    }

    /**
     * پاکسازی فایل‌های موقت یک آپلود خاص
     */
    public function cleanupUpload($uploadId)
    {
        $tempPath = "temp_uploads/{$uploadId}";

        if ($this->localDisk->exists($tempPath)) {
            $this->localDisk->deleteDirectory($tempPath);
            return true;
        }

        return false;
    }

    private function acquireUploadLock(string $uploadId)
    {
        $lockDirectory = storage_path('app/temp_uploads');

        if (!is_dir($lockDirectory)) {
            mkdir($lockDirectory, 0775, true);
        }

        $lockPath = "{$lockDirectory}/{$uploadId}.lock";
        $handle = fopen($lockPath, 'c');

        if (!$handle) {
            throw new Exception('امکان ایجاد قفل آپلود وجود ندارد.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new Exception('پردازش نهایی این فایل در حال انجام است.');
        }

        return $handle;
    }

    private function releaseUploadLock($handle): void
    {
        if ($handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

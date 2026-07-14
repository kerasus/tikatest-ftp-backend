<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

class DatabaseBackupController extends Controller
{
    /**
     * Generate a SQL backup of the entire database using mysqldump and save it to storage.
     *
     * @return JsonResponse
     */
    public function backupDatabase()
    {
        // Get database configuration from .env
        $dbHost = config('database.connections.mysql.host');
        $dbPort = config('database.connections.mysql.port');
        $dbName = config('database.connections.mysql.database');
        $dbUser = config('database.connections.mysql.username');
        $dbPass = config('database.connections.mysql.password');

        // Generate a filename with the current date
        $filename = 'backup-' . now()->format('Y-m-d') . '.sql';

        // Define the path to save the backup file
        $backupPath = storage_path("app/backups/{$filename}");

        // Build the mysqldump command
        $command = "mysqldump --user={$dbUser} --password={$dbPass} --host={$dbHost} --port={$dbPort} {$dbName} > {$backupPath}";

        // Execute the command
        exec($command, $output, $returnVar);

        // Check if the command was successful
        if ($returnVar === 0) {
            return response()->json([
                'message' => 'Database backup created successfully.',
                'file_path' => $backupPath,
            ], Response::HTTP_OK);
        } else {
            return response()->json([
                'message' => 'Failed to create database backup.',
                'error_code' => $returnVar,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

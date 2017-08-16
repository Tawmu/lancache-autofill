<?php

namespace Zeropingheroes\LancacheAutofill\Console\Commands\Steam;

use Illuminate\Console\Command;
use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class StartDownloading extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'steam:start-downloading';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start downloading the Steam apps in the queue';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {       
        if ( $this->queuedItems() == 0 )
        {
            $this->error('Queue is empty - nothing to download');
            die();
        }

        // Loop through all apps to process
        while( $app = $this->nextApp() ) {
            
            $this->info('Starting download of ' . $app->name );

            try {
                $arguments = 
                [
                    'login'                         => 'anonymous',
                    '@sSteamCmdForcePlatformType'   => 'windows',
                    'force_install_dir'             => '/tmp/steam/'.$app->appid,
                    'app_license_request'           => $app->appid,
                    'app_update'                    => $app->appid,
                    'quit'                          => null,
                ];

                $argumentString = null;

                // Build argument string
                foreach($arguments as $argument => $value) {
                    $argumentString .= "+$argument $value ";
                }

                // Start SteamCMD with the arguments, using "unbuffer"
                // as SteamCMD buffers output when it is not run in a
                // tty, which prevents us showing output line by line
                $process = new Process('unbuffer steamcmd.sh '.$argumentString);
                
                // Set a long timeout as downloading could take a while
                $process->setTimeout(14400);

                // Show SteamCMD output line by line
                $process->run(function ($type, $buffer) {
                    $this->line(str_replace(["\r", "\n"], '', $buffer));
                });

                if (!$process->isSuccessful())
                    throw new ProcessFailedException($process);

                $this->info('Successfully completed download of ' . $app->name );
                $this->updateQueueItemStatus($app->id, 'completed');

            } catch (ProcessFailedException $e) {
                if($process->getExitCode() == 127) {
                    $this->error('SteamCMD not found - please ensure it is in your $PATH');
                    die();
                }

                // Create an array of SteamCMD's output (removing excess newlines)
                $lines = explode(PHP_EOL,trim($process->getOutput()));

                // Get the last line (removing ANSI codes)
                $lastLine = preg_replace('#\x1b\[[0-9;]*[a-zA-Z]#', '', end($lines));

                $this->error('Failed to download ' . $app->name );
                $this->updateQueueItemStatus($app->id, 'failed', $lastLine );

            }
        }
    }

    private function nextApp()
    {
        return Capsule::table('steam_queue')
                        ->where('status', 'queued')
                        ->first();
    }

    private function updateQueueItemStatus( $id, $status, $message = null )
    {
        return Capsule::table('steam_queue')
                        ->where('id', $id)
                        ->update(['status' => $status, 'message' => $message]);
    }

    private function queuedItems()
    {
        return Capsule::table('steam_queue')
                        ->where('status', 'queued')
                        ->count();
    }
}
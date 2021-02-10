<?php

namespace App\Console\Commands;

use App\Console\Commands\ProjectAnalyzer;
use App\Helpers\MainHelper;
use App\ProjectMeta;
use App\RunningInstance;

class AnalyseAllProjects extends ProjectAnalyzer
{
    /* Regex to search by GitHub rules of string "test" */
    const GITHUB_SEARCH_REGEX = '(((^|[^A-Za-z0-9])[Tt])|([a-z]T))(?i)est(?-i)([^a-z0-9])';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'projects:analyseAll
                            {--instanceNo= : Number of the command instance}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze all projects on Github for presence of tests';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', '7500M');

        $instanceNo = $this->option('instanceNo') ?? 0;

        // be sure, that only one instance is active at a time (cron sometimes overrun the script, caused by app cache)
        if (RunningInstance::where(RunningInstance::INSTANCE_NO, $instanceNo)->where(RunningInstance::COMMAND, self::class)->count() > 0){
            throw new \Exception('Instance '.$instanceNo.' already running.');
        }
        else{
            $instance = new RunningInstance();
            $instance->instance_no = $instanceNo;
            $instance->command = self::class;
            $instance->save();
        }

        try{
            // Iterate projects while available
            do{
                $projects = ProjectMeta::orderBy(ProjectMeta::FOUND_TEST_IN_BODY_JAVA, 'desc')
                    ->skip(MainHelper::PAGINATION*10*$instanceNo)
                    ->take(100)
                    ->doesntHave('projectRealTestsStat')
                    ->get();

                $this->process($projects, $instanceNo);
            } while($projects->count() > 0);
        } catch (\Exception $e){
            $instance->delete();
            throw $e;
        }

        return 0;
    }
}

<?php

namespace App\Console\Commands;

use App\Console\Commands\AnalyseAllProjects;
use App\ProjectMeta;
use App\ProjectRealTestsStat;
use Goutte\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

abstract class ProjectAnalyzer extends Command
{
    /** @var ProjectRealTestsStat $stat */
    private $stat = null; // model to save results

    private $instanceNo;

    const FRAMEWORK_IMPORTS = [
        'junit3' => 'junit.framework',
        'junit4' => 'org.junit',
        'testng' => 'org.testng',
        'artos' => 'com.artos',
        'beanTest' => 'info.novatec.bean-test',
        'cunit' => 'edu.rice.cs.cunit',
        'grandTestAuto' => 'org.GrandTestAuto',
        'arquillian' => 'org.jboss.arquillian',
        'etlunit' => 'org.bitbucket.bradleysmithllc.etlunit',
        'havarunner' => 'com.github.havarunner',
        'jexample' => 'ch.unibe.jexample',
        'jnario' => 'org.jnario',
        'assertj' => 'org.assertj',
        'hamcrest' => 'org.hamcrest',
        'xmlunit' => 'org.xmlunit',
        'beanSpec' => 'org.beanSpec',
        'cactus' => 'org.apache.cactus',
        'concordion' => 'org.concordion',
        'cucumber' => 'io.cucumber',
        'cuppa' => 'org.forgerock.cuppa',
        'dbunit' => 'org.dbunit',
        'easyMock' => 'org.easymock.EasyMock',
        'groboutils' => 'net.sourceforge.groboutils',
        'jbehave' => 'org.jbehave',
        'jdave' => 'org.jdave',
        'jgiven' => 'com.tngtech.jgiven',
        'jmock' => 'org.jmock',
        'jmockit' => 'org.jmockit',
        'jukito' => 'org.jukito',
        'junitee' => 'org.junitee',
        'mockito' => 'org.mockito',
        'mockrunner' => 'com.mockrunner',
        'needle' => 'de.akquinet.jbosscc.needle',
        'openpojo' => 'com.openpojo',
        'powermock' => 'org.powermock',
        'spock' => 'spock.lang',
        'springframework' => 'org.springframework.test',
        'selenide' => 'com.codeborne.selenide',
        'serenitybdd' => 'net.serenitybdd',
        'selenium' => 'org.openqa.selenium',
        'robotframework' => 'org.robotframework',
        'tellurium' => 'org.tellurium',
    ];

    public function process(Collection $projects, int $instanceNo) : void
    {
        /** @var ProjectMeta $project */
        foreach($projects as $project){
            $projectName = $project->full_name;
            $projectDir = 'project-'.$instanceNo;
            $this->instanceNo = $instanceNo;

            $this->info('Processing project: '.$projectName);

            try {
                // try to download directly master
                Storage::put($projectDir.'.zip', file_get_contents('https://github.com/'.$projectName.'/archive/master.zip'));
            }
            // if not found, try to detect main branch name
            catch(\ErrorException $e){
                $client = new Client();
                $guzzleClient = new \GuzzleHttp\Client([
                    'timeout' => 60,
                    'allow_redirects' => [
                        'max'             => 10,        // allow at most 10 redirects.
                    ]
                ]);

                $dom = $client->setClient($guzzleClient)
                    ->request('GET', 'https://github.com/'.$projectName.'/branches');

                /** @var Response $response */
                $response = $client->getResponse();

                // unavailable projects
                $unavailableProjectMessages = [
                    'This repository is empty',
                    'This repository has been disabled',
                ];
                foreach ($unavailableProjectMessages as $message){
                    if (strpos($dom->text(), $message) !== false){
                        $this->setUnableToDownload($project, $projectName);
                        continue;
                    }
                }

                switch($response->getStatusCode()){
                    case 404:
                    case 451:
                        $this->setUnableToDownload($project, $projectName);
                        continue 2;
                    case 200:
                        // continue
                        break;
                    default:
                        throw new \Exception('Unexpected status code: '.$response->getStatusCode());
                }

                $branches = $dom->filter('branch-filter-item');
                $mainBranch = $branches->getNode(0)->attributes->getNamedItem('branch')->nodeValue;

                $metas = $dom->filter('meta[property="og:title"]');
                $projectName = $metas->getNode(0)->attributes->getNamedItem('content')->nodeValue;

                // try to download new scraped url
                Storage::put($projectDir.'.zip', file_get_contents('https://github.com/'.$projectName.'/archive/'.urlencode($mainBranch).'.zip'));
            }

            $fullPath = Storage::path($projectDir);


            exec('stat '.$fullPath, $out, $rc);
            // delete project if dir already exists
            if ($rc == 0){ // file exists
                exec('rm -rf '.$fullPath, $out, $rc);
            }

            // unzip
            exec('unzip '.$fullPath.'.zip -d '.$fullPath.' 2>&1', $out, $rc);
            if ($rc != 0) {
                $stat = new ProjectRealTestsStat();
                $stat->project_meta_id = $project->id;
                $stat->full_name = $projectName;
                $stat->unable_to_unzip = true;
                $stat->instance = $this->instanceNo;
                $stat->save();
                continue;
            }

            // check for project concurence for current instance
            $dirCount = count(Storage::directories($projectDir));
            if ($dirCount > 1){
                throw new \Exception('Directory '.$projectDir.' have multiple projects.');
            }
            elseif($dirCount == 0){
                throw new \Exception('Directory '.$projectDir.' is empty.');
            }

            // analyze project
            $this->analyze($project, $fullPath, $projectName);
        }
    }

    /**
     * Label project as unable to download
     * 
     * @param  ProjectMeta  $project
     * @param  string  $projectName
     */
    private function setUnableToDownload(ProjectMeta $project, string $projectName): void
    {
        $stat = new ProjectRealTestsStat();
        $stat->project_meta_id = $project->id;
        $stat->full_name = $projectName;
        $stat->unable_to_download = true;
        $stat->instance = $this->instanceNo;
        $stat->save();
    }

    /**
     * Analyze particular project
     *
     * @param  ProjectMeta  $project
     * @param  string  $path
     * @param  string  $projectName
     */
    private function analyze(ProjectMeta $project, string $path, string $projectName) : void
    {
        $this->stat = new ProjectRealTestsStat();
        $this->stat->project_meta_id = $project->id;
        $this->stat->full_name = $projectName;

        $this->info('Running AG...');
        $agData = $this->getAgData($path);
        $this->stat->java_kt_processed_files = $agData->count();

        $this->info('Running MAIN analysis...');
        $t = microtime(true);
        $files = $this->processFilesContainingTestWord($agData);
        $this->stat->real_tests_java_kt_execution_time = (microtime(true)-$t)*1000;

        if ($files->count() != $this->stat->java_kt_processed_files){
            throw new \Exception('java_kt_processed_files is '.$this->stat->java_kt_processed_files.' and number of files '.$files->count());
        }

        $this->info('Running LOC...');
        file_put_contents($path.'/files.txt', $files->implode("\n"));
        $this->locOfProcessed($path);
        $this->stat->instance = $this->instanceNo;
        $this->stat->save();
        $this->info('-----------------------------------------------------------');
    }

    /**
     * Count how many LOC were processed
     * 
     * @param  string  $path
     * @throws \Exception
     */
    private function locOfProcessed(string $path): void
    {
        exec('cloc --json --list-file='.$path.'/files.txt 2>&1', $out, $rc);
        if ($rc != 0) throw new \Exception('Unable to count LOC (RC: '.$rc.'): '.json_encode($out));

        $this->stat->cloc_output = json_decode(implode('', $out), true);
        $this->stat->loc = $this->stat->cloc_output['SUM']['code'];
    }

    /**
     * Get ag data containing collection og "full_path/filename.ext:FOUND_STRING_OCCURRENCES"
     *
     * @param  string  $projectPath
     * @return Collection
     */
    private function getAgData(string $projectPath) : Collection
    {
        exec('ag -c --java --kotlin \''.AnalyseAllProjects::GITHUB_SEARCH_REGEX.'\' '.$projectPath, $out, $rc);
        return collect($out);
    }

    /**
     * Returns number of real tests in project
     *
     * @param  Collection  $agData
     * @return Collection
     */
    private function processFilesContainingTestWord(Collection $agData) : Collection
    {
        $files = new Collection();
        $totalNoOfTests = 0;
        foreach (self::FRAMEWORK_IMPORTS as $framework => $import) $frameworks_occurrence[$framework] = 0;
        $ratios = null;
        foreach ($agData as $line){
            $lineParts = explode(':', $line); // parsing line output of 'ag'
            if (count($lineParts) < 2) {
                throw new \Exception('Wrong line format: '.$line);
            }

            $testOccurrences = array_pop($lineParts);

            $file = implode(':', $lineParts);
            $files->push($file);
            $this->stat->searched_test_words_in_java_kt += $testOccurrences; // sum of number of found "test" word in project
            // if file does not exists, return null
            try {
               $fileContent = file_get_contents($file);
            } catch (\Exception $e){
                // mostly empty string at the end of 'ag' output
                continue;
            }

            // rm oneline comments
            $fileContent = preg_replace('#//.*'.PHP_EOL.'#', PHP_EOL, $fileContent);

            // remove multiline comment start and end tag, when in a sting
            $fileContent = preg_replace('#".*/\*.*"#', '', $fileContent);
            $fileContent = preg_replace('#".*\*/.*"#', '', $fileContent);

            // rm multiline comments
            $fileContent = preg_replace('#/\*.*?\*/#s', '', $fileContent);

            // rm all until first occurrence of "class" (testng can annotate by @Test also whole class, we need to count only annotations of methods)
            $fileContentWithoutImports = substr($fileContent, strpos($fileContent, 'class ') ?? 0);

            $pathParts = explode('/', $file);
            $filename = array_pop($pathParts);
            $ext = $this->file_ext($filename);

            // independent counts
            $data = [
               'annotations' => preg_match_all("/@Test/", $fileContentWithoutImports ,$match) - preg_match_all("/\".*@Test*/", $fileContentWithoutImports ,$match), // minus string occurrences
               'junit3' => preg_match_all("/import\s+.*junit\.framework/", $fileContent ,$match),
               'junit4' => preg_match_all("/import\s+.*org\.junit/", $fileContent ,$match),
               'testng' => preg_match_all("/import\s+.*org\.testng/", $fileContent ,$match),
            ];

            // extension dependent counts
            switch ($ext){
               case 'java':
               case 'properties':
                   $data['startsWithTest'] = preg_match_all("/public(\s+.*\s+|\s+)void(\s+.*\s+|\s+)[Tt]est.*\s*\(/", $fileContentWithoutImports ,$match);
                   $data['endsWithTest'] = preg_match_all("/public(\s+.*\s+|\s+)void(\s+.*\s+|\s+)[a-zA-Z$\_]{1}.*Test\s*\(/", $fileContentWithoutImports ,$match);
                   $data['publicMethods'] = preg_match_all("/public(\s+.*\s+|\s+)void\s+.*\s*\(/", $fileContentWithoutImports ,$match);
                   $data['publicMethodsInRoot'] = $this->countPublicMethodInRoot($fileContentWithoutImports, $ext);
                   $data['includesMain'] = preg_match_all("/public\s+static\s+void\s+main\s*\(/", $fileContentWithoutImports ,$match) - preg_match_all("/\".*public\s+static\s+void\s+main\s*\(/", $fileContentWithoutImports ,$match); // minus string occurences
                   break;
               case 'kt':
                   $data['startsWithTest'] = preg_match_all("/(^|\s+)fun\s+[`]{0,1}[Tt]est.*[`]{0,1}\s*\(/", $fileContentWithoutImports ,$match)
                       - preg_match_all("/(^|\s+)(private|protected|internal)\s+fun\s+[`]{0,1}[Tt]est.*[`]{0,1}\s*\(/", $fileContentWithoutImports ,$match);
                   $data['endsWithTest'] = preg_match_all("/\s*fun\s+[`]{0,1}[a-zA-Z$\_]{1}.*[Tt]est[`]{0,1}\s*\(/", $fileContentWithoutImports ,$match)
                       - preg_match_all("/(^|\s+)(private|protected|internal)\s+fun\s+[`]{0,1}[a-zA-Z$\_]{1}.*[Tt]est[`]{0,1}\s*\(/", $fileContentWithoutImports ,$match);
                   $data['publicMethods'] = preg_match_all("/(^|\s+)fun\s+.+\s*\(/", $fileContentWithoutImports ,$match)
                       - preg_match_all("/(^|\s+)(private|protected|internal)\s+fun\s+.+\s*\(/", $fileContentWithoutImports ,$match);
                   $data['publicMethodsInRoot'] = $this->countPublicMethodInRoot($fileContentWithoutImports, $ext);
                   $data['includesMain'] = preg_match_all("/(^|\s+)fun\s+main\s*\(/", $fileContentWithoutImports ,$match); // minus string occurences
                   break;
               default:
                   $this->error('Unsupported file extension: '.$ext);
                   $data['startsWithTest'] = 0;
                   $data['endsWithTest'] = 0;
                   $data['publicMethods'] = 0;
                   $data['publicMethodsInRoot'] = 0;
                   $data['includesMain'] = 0;
            }

            $predictionFrom = null;
            $nonClassContent = substr($fileContent, 0, strpos($fileContent, 'class '));
            // testng has an special behaviour
            if (preg_match_all("/import(\s+.*\s+|\s+)org\.testng\.annotations\.Test/", $nonClassContent, $match) || preg_match_all("/import(\s+.*\s+|\s+)org\.testng\.annotations\.\*/", $nonClassContent, $match)){

               // if class is annotated, all public methods are considered as tests
               if (substr_count($nonClassContent, '@Test')){
                   $numberOfTests = $data['publicMethodsInRoot'];
                   $this->stat->prediction_from_public_methods_in_root += 1;
               }
               // use annotation count if junit @Test annotation is imported and class is not annotated with @Test
               else{
                   $numberOfTests = $data['annotations'];
                   $this->stat->prediction_from_annotations += 1;
               }
            }
            // use annotation count if junit @Test annotation is imported before first class occurrence
            elseif ($data['junit4']){
                $numberOfTests = $data['annotations'];
                $this->stat->prediction_from_annotations += 1;
            }
            // junit3 methods starts with "test"
            elseif ($data['junit3']){
                $numberOfTests = $data['startsWithTest'];
                $this->stat->prediction_from_starts_with_test += 1;
            }
            else{
               if ($data['startsWithTest'] > 0){
                   $numberOfTests = $data['startsWithTest'];
                   $this->stat->prediction_from_starts_with_test += 1;
               }
               elseif ($data['annotations'] > 0) {
                   $numberOfTests = $data['annotations'];
                   $this->stat->prediction_from_annotations += 1;
               }
               else{
                   $numberOfTests = 0;
               }
            }

            // apache.cactus can include beginXXX and endXXX, which are also tests
            if (preg_match_all("/import(\s+.*\s+|\s+)org\.apache\.cactus/", $nonClassContent, $match)){

               // in beginXXX there are normally no asserts
               // $numberOfTests += preg_match_all("/public +.*void *.* +begin.* *\(/", $fileContentWithoutImports ,$match);
               // in endXXX normally http response code is checked
               $numberOfTests += preg_match_all("/public(\s+.*\s+|\s+)void(\s+.*\s+|\s+)end.*\s*\(/", $fileContentWithoutImports ,$match);
               $this->stat->prediction_from_apache_cactus += 1;
            }

            // final processing
            $totalNoOfTests += $numberOfTests;
            $ratios[] = [$testOccurrences, $numberOfTests];

            // count framework occurrence
            foreach (self::FRAMEWORK_IMPORTS as $framework => $import) {
                $frameworks_occurrence[$framework] += preg_match_all("/".$import."/", $fileContent ,$match);
            }
        }

        $this->stat->ratios = $ratios;
        $this->stat->frameworks_occurrence = $frameworks_occurrence;
        $this->stat->real_tests_java_kt = $totalNoOfTests;

        return $files;
    }

    /**
     * Counts public method in first level of a class
     *
     * @param $fileContentWithoutImports
     * @param $ext
     * @return false|int
     */
    private function countPublicMethodInRoot(string $fileContentWithoutImports, string $ext) : int{
        // remove all content of subblocks
        $fileContentWithoutImportsAndMainBlock = substr($fileContentWithoutImports, strpos($fileContentWithoutImports, '{')+1);
        $fileContentWithoutImportsAndMainBlock = substr($fileContentWithoutImportsAndMainBlock, 0, strrpos($fileContentWithoutImportsAndMainBlock, '}'));
        $fileContentWithoutSubblocks = preg_replace('/\{([^\{\}]++|(?R))*\}/', '', $fileContentWithoutImportsAndMainBlock);

        switch ($ext){
            case 'java':
            case 'properties':
                $regex = 'public(\s+.*\s+|\s+)void\s+.+\s*\(';
                break;
            case 'kt':
                $regex = '(^|\s+)fun\s+.+\s*\(';
        }

        $numberOfTests = preg_match_all("/".$regex."/", $fileContentWithoutSubblocks,$match);

        $except = [
            '@BeforeTest',
            '@AfterTest',
            '@BeforeMethod',
            '@AfterMethod',
        ];
        foreach ($except as $item) {
            $numberOfTests -= preg_match_all("/".$item."[\s\n]*".$regex."/", $fileContentWithoutSubblocks,$match);
        }

        if ($ext == 'kt'){
            $numberOfTests -=  preg_match_all("/(^|\s+)(private|protected|internal)\s+fun\s+.+\s*\(/", $fileContentWithoutSubblocks,$match);
        }

        return $numberOfTests;
    }

    /**
     * Get file extension 
     * 
     * @param $filename
     * @return string|string[]|null
     */
    function file_ext($filename) {
        return preg_match('/\./', $filename) ? preg_replace('/^.*\./', '', $filename) : '';
    }
}

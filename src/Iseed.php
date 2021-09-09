<?php

namespace cpyun\iseed;

use think\facade\Config;
use think\facade\Db;
use think\Filesystem;
use Illuminate\Support\Composer;

use cpyun\iseed\exception\TableNotFoundException;

class Iseed
{
    /**
     * Name of the database upon which the seed will be executed.
     *
     * @var string
     */
    protected $databaseName;

    /**
     * New line character for seed files.
     * Double quotes are mandatory!
     *
     * @var string
     */
    private $newLineCharacter = PHP_EOL;

    /**
     * Desired indent for the code.
     * For tabulator use \t
     * Double quotes are mandatory!
     *
     * @var string
     */
    private $indentCharacter = "    ";

    /**
     * @var Composer
     */
    private $composer;

    public function __construct(Filesystem $filesystem = null)
    {
        Config::set([
            'default' => 'local',
            'disks'   => [
                'local'  => [
                    'type' => 'local',
                    'root' => app()->getRootPath(),
                ]
            ]
        ], 'filesystem');
        $this->files = $filesystem ?: new Filesystem;
        //$this->composer = $composer ?: new Composer($this->files);
    }

    public function readStubFile($file)
    {
        $buffer = file($file, FILE_IGNORE_NEW_LINES);
        return implode(PHP_EOL, $buffer);
    }

    /**
     * Generates a seed file.
     * @param string $table
     * @param string $prefix
     * @param string $suffix
     * @param string $database
     * @param int $max
     * @param int $chunkSize
     * @param string $exclude
     * @param string $prerunEvent
     * @param string $postrunEvent
     * @param bool $dumpAuto
     * @param bool $indexed
     * @param string $orderBy
     * @param string $direction
     * @return bool
     */
    public function generateSeed($table, $prefix=null, $suffix=null, $database = null, $max = 0, $chunkSize = 0, $exclude = null, $prerunEvent = null, $postrunEvent = null, $dumpAuto = true, $indexed = true, $orderBy = null, $direction = 'ASC')
    {
        if (!$database) {
            $database = config('database.default');
        }

        $this->databaseName = $database;

        // Check if table exists
        if (!$this->hasTable($table)) {
            throw new TableNotFoundException("Table $table was not found.");
        }

        // Get the data
        $data = $this->getData($table, $max, $exclude, $orderBy, $direction);

        // Repack the data
        $dataArray = $this->repackSeedData($data);

        // Generate class name
        $className = $this->generateClassName($table, $prefix, $suffix);

        // Get template for a seed file contents
        $stub = $this->readStubFile($this->getStubPath() . '/seed.stub');

        // Get a seed folder path
        $seedPath = $this->getSeedPath();

        // Get a app/database/seeds path
        $seedsPath = $this->getPath($className, $seedPath);

        // Get a populated stub file
        $seedContent = $this->populateStub(
            $className,
            $stub,
            $table,
            $dataArray,
            $chunkSize,
            $prerunEvent,
            $postrunEvent,
            $indexed
        );

        $this->files->put($seedsPath, $seedContent);

        // Run composer dump-auto
        if ($dumpAuto) {
            //$this->composer->dumpAutoloads();
        }

        // Update the DatabaseSeeder.php file
        //return $this->updateDatabaseSeederRunMethod($className) !== false;

        return true;
    }

    /**
     * Get a seed folder path
     * @return string
     */
    public function getSeedPath()
    {
        return config('iseed.path');
    }

    /**
     * Get the Data
     * @param string $table
     * @param $max
     * @param null $exclude
     * @param null $orderBy
     * @param string $direction
     * @return Array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getData($table, $max, $exclude = null, $orderBy = null, $direction = 'ASC')
    {
        $result = Db::connect($this->databaseName)->table($table);

        if (!empty($exclude)) {
            $allColumns = Db::connect($this->databaseName)->getFields($table);
            $allColumns = array_keys($allColumns);
            $result = $result->field(array_diff($allColumns, $exclude));
        }

        if($orderBy) {
            $result = $result->order($orderBy, $direction);
        }

        if ($max) {
            $result = $result->limit($max);
        }

        return $result->select();
    }

    /**
     * Repacks data read from the database
     * @param  array|object $data
     * @return array
     */
    public function repackSeedData($data)
    {
        if (!is_array($data)) {
            $data = $data->toArray();
        }
        $dataArray = array();
        if (!empty($data)) {
            foreach ($data as $row) {
                $rowArray = array();
                foreach ($row as $columnName => $columnValue) {
                    $rowArray[$columnName] = $columnValue;
                }
                $dataArray[] = $rowArray;
            }
        }
        return $dataArray;
    }

    /**
     * Checks if a database table exists
     * @param string $table
     * @return boolean
     */
    public function hasTable($table)
    {
        return in_array($table, Db::connect($this->databaseName)->getTables());
    }

    /**
     * Generates a seed class name (also used as a filename)
     * @param  string  $table
     * @param  string  $prefix
     * @param  string  $suffix
     * @return string
     */
    public function generateClassName($table, $prefix=null, $suffix=null)
    {
        $tableString = '';
        $tableName = explode('_', $table);
        foreach ($tableName as $tableNameExploded) {
            $tableString .= ucfirst($tableNameExploded);
        }
        return ($prefix ? $prefix : '') . ucfirst($tableString) . 'Table' . ($suffix ? $suffix : '') . 'Seeder';
    }

    /**
     * Get the path to the stub file.
     * @return string
     */
    public function getStubPath()
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'stubs';
    }

    /**
     * Populate the place-holders in the seed stub.
     * @param  string   $class
     * @param  string   $stub
     * @param  string   $table
     * @param  string   $data
     * @param  int      $chunkSize
     * @param  string   $prerunEvent
     * @param  string   $postunEvent
     * @return string
     */
    public function populateStub($class, $stub, $table, $data, $chunkSize = null, $prerunEvent = null, $postrunEvent = null, $indexed = true)
    {
        $chunkSize = $chunkSize ?: config('iseed.chunk_size');

        $inserts = '';
        $chunks = array_chunk($data, $chunkSize);
        foreach ($chunks as $chunk) {
            $this->addNewLines($inserts);
            $this->addIndent($inserts, 2);
            $inserts .= sprintf(
                "Db::table('%s')->insertAll(%s);",
                $table,
                $this->prettifyArray($chunk, $indexed)
            );
        }

        $stub = str_replace('{{class}}', $class, $stub);

        $prerunEventInsert = '';
        if ($prerunEvent) {
            $prerunEventInsert .= "\$response = Event::until(new $prerunEvent());";
            $this->addNewLines($prerunEventInsert);
            $this->addIndent($prerunEventInsert, 2);
            $prerunEventInsert .= 'if ($response === false) {';
            $this->addNewLines($prerunEventInsert);
            $this->addIndent($prerunEventInsert, 3);
            $prerunEventInsert .= 'throw new Exception("Prerun event failed, seed wasn\'t executed!");';
            $this->addNewLines($prerunEventInsert);
            $this->addIndent($prerunEventInsert, 2);
            $prerunEventInsert .= '}';
        }

        $stub = str_replace(
            '{{prerun_event}}', $prerunEventInsert, $stub
        );

        if (!is_null($table)) {
            $stub = str_replace('{{table}}', $table, $stub);
        }

        $postrunEventInsert = '';
        if ($postrunEvent) {
            $postrunEventInsert .= "\$response = Event::until(new $postrunEvent());";
            $this->addNewLines($postrunEventInsert);
            $this->addIndent($postrunEventInsert, 2);
            $postrunEventInsert .= 'if ($response === false) {';
            $this->addNewLines($postrunEventInsert);
            $this->addIndent($postrunEventInsert, 3);
            $postrunEventInsert .= 'throw new Exception("Seed was executed but the postrun event failed!");';
            $this->addNewLines($postrunEventInsert);
            $this->addIndent($postrunEventInsert, 2);
            $postrunEventInsert .= '}';
        }

        $stub = str_replace(
            '{{postrun_event}}', $postrunEventInsert, $stub
        );

        $stub = str_replace('{{insert_statements}}', $inserts, $stub);

        return $stub;
    }

    /**
     * Create the full path name to the seed file.
     * @param  string  $name
     * @param  string  $path
     * @return string
     */
    public function getPath($name, $path)
    {
        return $path . '/' . $name . '.php';
    }

    /**
     * Prettify a var_export of an array
     * @param  array  $array
     * @return string
     */
    protected function prettifyArray($array, $indexed = true)
    {
        $content = ($indexed)
            ? var_export($array, true)
            : preg_replace("/[0-9]+ \=\>/i", '', var_export($array, true));

        $lines = explode("\n", $content);

        $inString = false;
        $tabCount = 3;
        for ($i = 1; $i < count($lines); $i++) {
            $lines[$i] = ltrim($lines[$i]);

            //Check for closing bracket
            if (strpos($lines[$i], ')') !== false) {
                $tabCount--;
            }

            //Insert tab count
            if ($inString === false) {
                for ($j = 0; $j < $tabCount; $j++) {
                    $lines[$i] = substr_replace($lines[$i], $this->indentCharacter, 0, 0);
                }
            }

            for ($j = 0; $j < strlen($lines[$i]); $j++) {
                //skip character right after an escape \
                if ($lines[$i][$j] == '\\') {
                    $j++;
                }
                //check string open/end
                else if ($lines[$i][$j] == '\'') {
                    $inString = !$inString;
                }
            }

            //check for openning bracket
            if (strpos($lines[$i], '(') !== false) {
                $tabCount++;
            }
        }

        $content = implode("\n", $lines);

        return $content;
    }

    /**
     * Adds new lines to the passed content variable reference.
     *
     * @param string    $content
     * @param int       $numberOfLines
     */
    private function addNewLines(&$content, $numberOfLines = 1)
    {
        while ($numberOfLines > 0) {
            $content .= $this->newLineCharacter;
            $numberOfLines--;
        }
    }

    /**
     * Adds indentation to the passed content reference.
     *
     * @param string    $content
     * @param int       $numberOfIndents
     */
    private function addIndent(&$content, $numberOfIndents = 1)
    {
        while ($numberOfIndents > 0) {
            $content .= $this->indentCharacter;
            $numberOfIndents--;
        }
    }

    /**
     * Cleans the iSeed section
     * @return bool
     */
    public function cleanSection()
    {
        $databaseSeederPath = config('iseed.path') . '/DatabaseSeeder.php';

        $content = $this->files->read($databaseSeederPath);

        $content = preg_replace("/(\#iseed_start.+?)\#iseed_end/us", "#iseed_start\n\t\t#iseed_end", $content);

        return $this->files->put($databaseSeederPath, $content) !== false;
    }

    /**
     * Updates the DatabaseSeeder file's run method (kudoz to: https://github.com/JeffreyWay/Laravel-4-Generators)
     * @param  string  $className
     * @return bool
     */
    public function updateDatabaseSeederRunMethod($className)
    {
        $databaseSeederPath = config('iseed.path') . '/DatabaseSeeder.php';

        $content = $this->files->read($databaseSeederPath);
        if (strpos($content, "\$this->call({$className}::class)") === false) {
            if (
                strpos($content, '#iseed_start') &&
                strpos($content, '#iseed_end') &&
                strpos($content, '#iseed_start') < strpos($content, '#iseed_end')
            ) {
                $content = preg_replace("/(\#iseed_start.+?)(\#iseed_end)/us", "$1\$this->call({$className}::class);{$this->newLineCharacter}{$this->indentCharacter}{$this->indentCharacter}$2", $content);
            } else {
                $content = preg_replace("/(run\(\).+?)}/us", "$1{$this->indentCharacter}\$this->call({$className}::class);{$this->newLineCharacter}{$this->indentCharacter}}", $content);
            }
        }

        return $this->files->put($databaseSeederPath, $content) !== false;
    }
}

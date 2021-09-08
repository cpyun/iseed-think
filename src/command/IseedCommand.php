<?php

namespace cpyun\iseed\command;

use think\facade\Db;
use think\facade\Config;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option as InputOption;
use think\console\input\Argument as InputArgument;

use cpyun\iseed\exception\TableNotFoundException;


class IseedCommand extends Command
{
    /**
     * The console command configure.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('iseed')
            ->addArgument('tables', InputArgument::REQUIRED, 'comma separated string of table names')

            ->addOption('clean', null, InputOption::VALUE_NONE, 'clean iseed section', null)
            ->addOption('force', null, InputOption::VALUE_NONE, 'force overwrite of all existing seed classes', null)
            ->addOption('database', null, InputOption::VALUE_OPTIONAL, 'database connection', Config::get('database.default'))
            ->addOption('max', null, InputOption::VALUE_OPTIONAL, 'max number of rows', null)
            ->addOption('chunksize', null, InputOption::VALUE_OPTIONAL, 'size of data chunks for each insert query', null)
            ->addOption('exclude', null, InputOption::VALUE_OPTIONAL, 'exclude columns', null)
            ->addOption('prerun', null, InputOption::VALUE_OPTIONAL, 'prerun event name', null)
            ->addOption('postrun', null, InputOption::VALUE_OPTIONAL, 'postrun event name', null)
            ->addOption('dumpauto', null, InputOption::VALUE_OPTIONAL, 'run composer dump-autoload', true)
            ->addOption('noindex', null, InputOption::VALUE_NONE, 'no indexing in the seed', null)
            ->addOption('orderby', null, InputOption::VALUE_OPTIONAL, 'orderby desc by column', null)
            ->addOption('direction', null, InputOption::VALUE_OPTIONAL, 'orderby direction', null)
            ->addOption('classnameprefix', null, InputOption::VALUE_OPTIONAL, 'prefix for class and file name', null)
            ->addOption('classnamesuffix', null, InputOption::VALUE_OPTIONAL, 'suffix for class and file name', null)

            ->setDescription('Generate seed file from table');
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    protected function execute(Input $input, Output $output): void
    {
        $this->input  = $input;
        $this->output = $output;

        $this->fire();
    }

    /**
     * Execute the console command (for <= 5.4).
     *
     * @return void
     */
    protected function fire(): void
    {
        if ($this->input->getOption('clean')) {
            app('iseed')->cleanSection();
        }

        $tables = explode(",", $this->input->getArgument('tables'));
        $max = intval($this->input->getOption('max'));
        $chunkSize = intval($this->input->getOption('chunksize'));
        $exclude = explode(",", $this->input->getOption('exclude'));
        $prerunEvents = explode(",", $this->input->getOption('prerun'));
        $postrunEvents = explode(",", $this->input->getOption('postrun'));
        $dumpAuto = intval($this->input->getOption('dumpauto'));
        $indexed = !$this->input->getOption('noindex');
        $orderBy = $this->input->getOption('orderby');
        $direction = $this->input->getOption('direction');
        $prefix = $this->input->getOption('classnameprefix');
        $suffix = $this->input->getOption('classnamesuffix');

        if ($max < 1) {
            $max = null;
        }

        if ($chunkSize < 1) {
            $chunkSize = null;
        }

        $tableIncrement = 0;
        foreach ($tables as $table) {
            $table = trim($table);
            $prerunEvent = null;
            if (isset($prerunEvents[$tableIncrement])) {
                $prerunEvent = trim($prerunEvents[$tableIncrement]);
            }
            $postrunEvent = null;
            if (isset($postrunEvents[$tableIncrement])) {
                $postrunEvent = trim($postrunEvents[$tableIncrement]);
            }
            $tableIncrement++;

            // generate file and class name based on name of the table
            list($fileName, $className) = $this->generateFileName($table, $prefix, $suffix);

            // if file does not exist or force option is turned on generate seeder
            if (!file_exists($fileName) || $this->input->getOption('force')) {
                $this->printResult(
                    app('iseed')->generateSeed(
                        $table,
                        $prefix,
                        $suffix,
                        $this->input->getOption('database'),
                        $max,
                        $chunkSize,
                        $exclude,
                        $prerunEvent,
                        $postrunEvent,
                        $dumpAuto,
                        $indexed,
                        $orderBy,
                        $direction
                    ),
                    $table
                );
                continue;
            }

            if ($this->output->confirm($this->input, 'File ' . $className . ' already exist. Do you wish to override it? [yes|no]', false)) {
                // if user said yes overwrite old seeder
                $this->printResult(
                    app('iseed')->generateSeed(
                        $table,
                        $prefix,
                        $suffix,
                        $this->input->getOption('database'),
                        $max,
                        $chunkSize,
                        $exclude,
                        $prerunEvent,
                        $postrunEvent,
                        $dumpAuto,
                        $indexed
                    ),
                    $table
                );
            }
        }

        return;
    }

    /**
     * Provide user feedback, based on success or not.
     *
     * @param  boolean $successful
     * @param  string $table
     * @return void
     */
    protected function printResult($successful, $table)
    {
        if ($successful) {
            $this->output->info("Created a seed file from table {$table}");
            return;
        }

        $this->output->error("Could not create seed file from table {$table}");
    }

    /**
     * Generate file name, to be used in test wether seed file already exist
     *
     * @param  string $table
     * @param  string $prefix
     * @param  string $suffix
     *
     * @return array
     */
    protected function generateFileName($table, $prefix=null, $suffix=null): array
    {
        $databaseType = $this->input->getOption('database') ? $this->input->getOption('database') : config('database.default');
        if (!in_array($table, Db::connect($databaseType)->getTables())) {
            throw new TableNotFoundException("Table $table was not found.");
        }

        // Generate class name and file name
        $className = app('iseed')->generateClassName($table, $prefix, $suffix);
        $seedPath = base_path() . config('iseed.path');
        return [
            $seedPath . '/' . $className . '.php',
            $className . '.php'
        ];
    }
}

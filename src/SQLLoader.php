<?php

declare(strict_types=1);

namespace Yajra\SQLLoader;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class SQLLoader
{
    /** @var InputFile[] */
    public array $inputFiles = [];

    /** @var TableDefinition[] */
    public array $tables = [];

    public Mode $mode = Mode::APPEND;

    public ?string $controlFile = null;

    public array $beginData = [];

    public ?string $connection = null;

    protected array $defaultColumns = [];

    public array $constants = [];

    protected ?string $disk = null;

    protected ?string $logPath = null;

    protected ?ProcessResult $result = null;

    protected bool $deleteFiles = false;

    protected string $logs = '';

    protected string $dateFormat = 'YYYY-MM-DD"T"HH24:MI:SS."000000Z"';

    public function __construct(public array $options = []) {}

    /**
     * Define mode to use.
     */
    public function mode(Mode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * Define table to load data into.
     */
    public function into(
        string $table,
        array $columns = [],
        ?string $terminatedBy = ',',
        ?string $enclosedBy = '"',
        bool $trailing = true,
        array $formatOptions = [],
        ?string $when = null,
        bool $csv = false,
        bool $withEmbedded = true,
    ): static {
        if (! $columns && $this->defaultColumns) {
            $columns = $this->createColumnsFromHeaders($table, $this->defaultColumns);
        }

        if (! $formatOptions) {
            $formatOptions = [
                "DATE FORMAT '".$this->dateFormat."'",
                "TIMESTAMP FORMAT '".$this->dateFormat."'",
                "TIMESTAMP WITH TIME ZONE '".$this->dateFormat."'",
                "TIMESTAMP WITH LOCAL TIME ZONE '".$this->dateFormat."'",
            ];
        }

        $columns = array_merge($columns, $this->constants);

        $this->tables[] = new TableDefinition(
            $table, $columns, $terminatedBy, $enclosedBy, $trailing, $formatOptions, $when, $csv, $withEmbedded
        );

        return $this;
    }

    public function createColumnsFromHeaders(string $table, array $columns): array
    {
        $columns = array_map('strtolower', $columns);
        $schemaColumns = collect(Schema::connection(config('sql-loader.connection'))->getColumns($table));

        $dates = $schemaColumns->filter(fn ($column) => in_array($column['type'], [
            'date',
            'datetime',
            'timestamp',
            'timestamp(6)',
        ]))->pluck('name')->toArray();

        $booleans = $schemaColumns->filter(
            fn ($column) => $column['nullable'] === false && $column['type'] === 'char' && $column['length'] === 1
        )->pluck('name')->toArray();

        foreach ($columns as $key => $column) {
            $escapedColumn = '"'.strtoupper((string) $column).'"';

            if (in_array($column, $dates)) {
                $columns[$key] = "{$escapedColumn} DATE";

                continue;
            }

            if (in_array($column, $booleans)) {
                $default = trim((string) $schemaColumns->where('name', $column)->first()['default']);
                $default = $default ?: "'0'"; // set value to 0 if default is empty since column is not nullable
                $columns[$key] = "{$escapedColumn} \"DECODE(:{$column}, '', {$default}, :{$column})\"";

                continue;
            }

            if (! $schemaColumns->contains('name', $column)) {
                $columns[$key] = "{$escapedColumn} FILLER";

                continue;
            }

            $columns[$key] = "{$escapedColumn}";
        }

        return $columns;
    }

    public function connection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Execute SQL Loader command.
     */
    public function execute(int $timeout = 3600): ProcessResult
    {
        if (! $this->tables) {
            throw new InvalidArgumentException('At least one table definition is required.');
        }

        if (! $this->inputFiles) {
            throw new InvalidArgumentException('Input file is required.');
        }

        $this->result = Process::timeout($timeout)->run($this->buildCommand());

        if ($this->deleteFiles) {
            if ($this->logPath && File::exists($this->logPath)) {
                $this->logs = File::get($this->logPath);
            }

            $this->deleteGeneratedFiles();
        }

        return $this->result; // @phpstan-ignore-line
    }

    /**
     * Build SQL Loader command.
     */
    protected function buildCommand(): string
    {
        $filesystem = $this->getDisk();

        $file = $this->getControlFile();
        $filesystem->put($file, $this->buildControlFile());
        $tns = $this->buildTNS();
        $binary = $this->getSqlLoaderBinary();
        $filePath = $filesystem->path($file);

        $command = "$binary userid=$tns control={$filePath}";
        if (! $this->logPath) {
            $this->logPath = str_replace('.ctl', '.log', (string) $filePath);
            $command .= " log={$this->logPath}";
        }

        return $command;
    }

    /**
     * Get the disk to use for control file.
     */
    public function getDisk(): Filesystem
    {
        if ($this->disk) {
            return Storage::disk($this->disk);
        }

        return Storage::disk(config('sql-loader.disk', 'local'));
    }

    /**
     * Set the disk to use for control file.
     */
    public function disk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Get the control file name.
     */
    protected function getControlFile(): string
    {
        if (! $this->controlFile) {
            $this->controlFile = Str::uuid().'.ctl';
        }

        return $this->controlFile;
    }

    /**
     * Build SQL Loader control file.
     */
    public function buildControlFile(): string
    {
        return (new ControlFileBuilder($this))->build();
    }

    /**
     * Build TNS connection string.
     */
    protected function buildTNS(): string
    {
        return TnsBuilder::make($this->getConnection());
    }

    /**
     * Create a new SQL Loader instance.
     */
    public static function make(array $options = []): SQLLoader
    {
        return new self($options);
    }

    public function getConnection(): string
    {
        return $this->connection ?? config('sql-loader.connection', 'oracle');
    }

    /**
     * Get the SQL Loader binary path.
     */
    public function getSqlLoaderBinary(): string
    {
        return config('sql-loader.sqlldr', 'sqlldr');
    }

    /**
     * Delete generated files after execution.
     */
    protected function deleteGeneratedFiles(): void
    {
        if ($this->logPath && File::exists($this->logPath)) {
            File::delete($this->logPath);
        }

        foreach ($this->inputFiles as $inputFile) {
            if ($inputFile->path !== '*') {
                File::delete($inputFile->path);
            }

            if ($inputFile->badFile && File::exists($inputFile->badFile)) {
                File::delete($inputFile->badFile);
            }

            if ($inputFile->discardFile && File::exists($inputFile->discardFile)) {
                File::delete($inputFile->discardFile);
            }
        }

        $filesystem = $this->getDisk();
        if ($this->controlFile && $filesystem->exists($this->controlFile)) {
            $filesystem->delete($this->controlFile);
        }
    }

    /**
     * Set the control file name.
     */
    public function as(string $controlFile): static
    {
        if (! Str::endsWith($controlFile, '.ctl')) {
            $controlFile .= '.ctl';
        }

        $this->controlFile = $controlFile;

        return $this;
    }

    /**
     * Set the log file path.
     */
    public function logsTo(string $path): static
    {
        $this->logPath = $path;

        return $this;
    }

    /**
     * Check if SQL Loader execution was successful.
     */
    public function successful(): bool
    {
        if (is_null($this->result)) {
            return false;
        }

        return $this->result->successful();
    }

    /**
     * Get the SQL Loader command, file path and result details.
     */
    public function debug(): string
    {
        $debug = 'Command:'.PHP_EOL.$this->buildCommand().PHP_EOL.PHP_EOL;
        $debug .= 'Control File:'.PHP_EOL.$this->buildControlFile().PHP_EOL;

        if ($this->result) {
            $debug .= 'Output:'.$this->result->output().PHP_EOL.PHP_EOL;
            $debug .= 'Error Output:'.PHP_EOL.$this->result->errorOutput().PHP_EOL;
            $debug .= 'Exit Code: '.$this->result->exitCode().PHP_EOL.PHP_EOL;
        }

        return $debug;
    }

    /**
     * Get the SQL Loader output.
     */
    public function output(): string
    {
        if (is_null($this->result)) {
            return 'No output available';
        }

        return $this->result->output();
    }

    /**
     * Get the SQL Loader error output.
     */
    public function errorOutput(): string
    {
        if (is_null($this->result)) {
            return 'No error output available';
        }

        return $this->result->errorOutput();
    }

    /**
     * Set the flag to delete generated files after execution.
     */
    public function deleteFilesAfterRun(bool $delete = true): static
    {
        $this->deleteFiles = $delete;

        return $this;
    }

    /**
     * Get the SQL Loader execution logs.
     */
    public function logs(): string
    {
        if ($this->logs) {
            return $this->logs;
        }

        if ($this->logPath && File::exists($this->logPath)) {
            return File::get($this->logPath);
        }

        return 'No log file available';
    }

    /**
     * Get the SQL Loader process result.
     */
    public function result(): ProcessResult
    {
        if (! $this->result) {
            throw new LogicException('Please run execute method first.');
        }

        return $this->result;
    }

    /**
     * Set the data to be loaded.
     */
    public function beginData(array $data): static
    {
        $this->inputFiles = [];
        $this->inFile('*');

        $this->beginData = $data;

        return $this;
    }

    /**
     * Define input file to load data from.
     */
    public function inFile(
        string $path,
        ?string $badFile = null,
        ?string $discardFile = null,
        ?string $discardMax = null,
        ?string $osFileProcClause = null,
    ): static {
        if (! File::exists($path) && ! Str::contains($path, ['*', '?'])) {
            throw new InvalidArgumentException("File [{$path}] does not exist.");
        }

        $this->inputFiles[] = new InputFile($path, $badFile, $discardFile, $discardMax, $osFileProcClause);

        return $this;
    }

    public function withHeaders(): static
    {
        $this->options(['skip=1']);

        $path = $this->inputFiles[0]->path;
        if (Str::contains($path, ['*', '?'])) {
            $files = File::allFiles(dirname($path));

            $headers = CsvFile::make($files[0]->getPathname(), 'r')->getHeaders();
        } else {
            $headers = CsvFile::make($this->inputFiles[0]->path, 'r')->getHeaders();
        }

        $this->defaultColumns = $headers;

        return $this;
    }

    /**
     * Set SQL Loader options.
     */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function dateFormat(string $format): static
    {
        $this->dateFormat = $format;

        return $this;
    }

    public function constants(array $constants): static
    {
        $this->constants = $constants;

        return $this;
    }
}

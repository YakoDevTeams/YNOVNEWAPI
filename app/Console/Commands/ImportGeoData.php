<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportGeoData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geo:import
                            {type=all : Type d\'import : pays, villes ou all}
                            {--chunk=1000 : Nombre de lignes à importer par exécution}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importe les pays et villes par batch depuis les fichiers CSV (database/data)';

    private const DATA_FILES = [
        'pays' => 'pays.csv',
        'villes' => 'villes.csv',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->argument('type');
        $chunk = max(1, (int) $this->option('chunk'));

        $types = $type === 'all' ? array_keys(self::DATA_FILES) : [$type];

        foreach ($types as $t) {
            if (!isset(self::DATA_FILES[$t])) {
                $this->error("Type inconnu : {$t} (pays, villes ou all)");
                return self::FAILURE;
            }
            $this->importBatch($t, $chunk);
        }

        return self::SUCCESS;
    }

    private function importBatch(string $type, int $chunk): void
    {
        $doneKey = "geo_import_{$type}_done";
        $offsetKey = "geo_import_{$type}_offset";
        $totalKey = "geo_import_{$type}_total";

        if (cache()->get($doneKey)) {
            $this->info("{$type} : import déjà terminé, rien à faire.");
            return;
        }

        $path = database_path('data/' . self::DATA_FILES[$type]);
        if (!is_file($path)) {
            $this->error("Fichier introuvable : {$path}");
            return;
        }

        $offset = (int) cache()->get($offsetKey, 0);
        $total = (int) cache()->get($totalKey, 0);

        $handle = fopen($path, 'r');
        if (!$handle) {
            $this->error("Impossible d'ouvrir {$path}");
            return;
        }

        // Header
        fgetcsv($handle);

        // Avance à l'offset (lignes déjà importées)
        for ($i = 0; $i < $offset; $i++) {
            if (fgetcsv($handle) === false) break;
        }

        $rows = [];
        $line = $offset;
        $eof = false;

        while (($data = fgetcsv($handle)) !== false) {
            $row = $this->mapRow($type, $data);
            if ($row) {
                $rows[] = $row;
            }
            $line++;
            $total++;

            if (count($rows) >= $chunk) {
                break;
            }
        }

        $eof = feof($handle);
        fclose($handle);

        foreach (array_chunk($rows, 500) as $batch) {
            DB::table($type === 'pays' ? 'pays' : 'villes')->insertOrIgnore($batch);
        }

        cache()->forever($offsetKey, $line);
        cache()->forever($totalKey, $total);

        $this->info("{$type} : " . count($rows) . " ligne(s) importée(s) (offset {$line}).");

        if ($eof || count($rows) < $chunk) {
            cache()->forever($doneKey, true);
            $this->info("{$type} : import terminé.");
        }
    }

    private function mapRow(string $type, array $data): ?array
    {
        if ($type === 'pays') {
            [$code, $indicatif, $flag, $flagUrl, $libelle] = array_pad($data, 5, null);
            if (empty($code)) return null;

            return [
                'uuid_pays' => (string) Str::uuid(),
                'codePays' => trim($code),
                'MonLibelle' => $libelle ? trim($libelle) : null,
                'Indicatif' => $indicatif ? trim($indicatif) : null,
                'Flag' => $flag ? trim($flag) : null,
                'FlagUrl' => $flagUrl ? trim($flagUrl) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // villes.csv : code_pays, lib_ville, code_ville
        [$codePays, $libVille, $codeVille] = array_pad($data, 3, null);
        if (empty($codeVille) || empty($codePays)) return null;

        return [
            'uuid_ville' => (string) Str::uuid(),
            'codeVille' => trim($codeVille),
            'MonLibelle' => $libVille ? trim($libVille) : null,
            'MonPays' => trim($codePays),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}

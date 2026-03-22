<?php

namespace App\Commands;

use App\Services\FatturaElettronica\XmlImportIdentityResolver;
use App\Services\FatturaElettronica\XmlInvoiceMapper;
use App\Services\FatturaElettronica\XmlInvoiceParser;
use App\Services\FicApiClient;
use App\Services\TokenStore;
use LaravelZero\Framework\Commands\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class EInvoiceImportCommand extends Command
{
    protected $signature = 'einvoice:import
        {path : XML file or folder to import}
        {--company-id= : Company ID (defaults to configured company)}
        {--dry-run : Parse and preview without creating documents}
        {--yes : Skip confirmation prompt}
        {--json : Output machine-readable JSON}';

    protected $description = 'Import one or more fattura elettronica XML files by recreating them through the API';

    public function handle(XmlInvoiceParser $parser, XmlInvoiceMapper $mapper, XmlImportIdentityResolver $resolver): int
    {
        $companyId = $this->option('company-id') ?: TokenStore::getCompanyId();

        if (! $companyId) {
            $this->error('No company selected. Use --company-id or run: fic company:set');

            return self::FAILURE;
        }

        if (! TokenStore::getAccessToken()) {
            $this->error('Not authenticated. Run: fic auth:login');

            return self::FAILURE;
        }

        try {
            $files = $this->collectFiles((string) $this->argument('path'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($files === []) {
            $this->error('No XML files found to import.');

            return self::FAILURE;
        }

        try {
            $api = new FicApiClient;
            $vatTypes = $this->fetchList($api, "/c/{$companyId}/settings/vat_types");
            $paymentMethods = $this->fetchList($api, "/c/{$companyId}/settings/payment_methods");
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $plans = [];

        foreach ($files as $file) {
            try {
                $invoice = $parser->parseFile($file);
                $identity = $resolver->resolve($api, $companyId, $invoice);
                $direction = $identity['direction'];
                $blockedReason = null;

                if ($direction === null) {
                    $blockedReason = 'This XML does not belong to the selected company: neither CedentePrestatore nor CessionarioCommittente matches it.';
                }

                $mapped = $direction !== null
                    ? $mapper->map($invoice, $direction, $vatTypes, $paymentMethods, $identity)
                    : ['warnings' => $identity['warnings'], 'summary' => [], 'payload' => []];

                $plans[] = [
                    'status' => $blockedReason === null ? 'ready' : 'blocked',
                    'file' => $file,
                    'file_name' => basename($file),
                    'direction' => $direction,
                    'source_format' => $invoice['source_format'] ?? 'xml',
                    'attachments' => $invoice['attachments'] ?? [],
                    'warnings' => $mapped['warnings'],
                    'summary' => $mapped['summary'],
                    'payload' => $mapped['payload'],
                    'blocked_reason' => $blockedReason,
                ];
            } catch (RuntimeException $exception) {
                $plans[] = [
                    'status' => 'invalid',
                    'file' => $file,
                    'file_name' => basename($file),
                    'warnings' => [],
                    'error' => $exception->getMessage(),
                ];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($this->jsonSummary($companyId, $plans), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->hasImportablePlans($plans) ? self::SUCCESS : self::FAILURE;
        }

        if (count($plans) === 1) {
            $this->renderSinglePlan($plans[0]);
        } else {
            $this->renderPlanTable($plans);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run completed. No documents were created.');

            return $this->hasImportablePlans($plans) ? self::SUCCESS : self::FAILURE;
        }

        $readyPlans = array_values(array_filter($plans, fn (array $plan) => $plan['status'] === 'ready'));

        if ($readyPlans === []) {
            $this->error('There are no importable XML files.');

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm('Proceed with the import?', true)) {
            $this->warn('Import cancelled.');

            return self::FAILURE;
        }

        $results = [];

        foreach ($readyPlans as $plan) {
            $results[] = $this->importPlan($api, (int) $companyId, $plan['direction'], $plan);
        }

        $this->renderResultTable($results);

        return collect($results)->contains(fn (array $result) => $result['status'] !== 'imported')
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function collectFiles(string $inputPath): array
    {
        $path = realpath($inputPath) ?: $inputPath;

        if (is_file($path)) {
            return [realpath($path) ?: $path];
        }

        if (! is_dir($path)) {
            throw new RuntimeException("Path not found: {$inputPath}");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! in_array(strtolower($file->getExtension()), ['xml', 'p7m'], true)) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchList(FicApiClient $api, string $uri): array
    {
        $response = $api->get($uri);

        if ($response->failed()) {
            throw new RuntimeException('Unable to fetch import settings: '.$api->errorMessage($response));
        }

        return $response->json('data', []);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function importPlan(FicApiClient $api, int $companyId, string $direction, array $plan): array
    {
        $payload = $plan['payload'];

        $attachmentPath = $this->prepareAttachmentUpload($plan);

        if ($attachmentPath !== null) {
            $attachmentEndpoint = $direction === 'issued'
                ? "/c/{$companyId}/issued_documents/attachment"
                : "/c/{$companyId}/received_documents/attachment";

            $attachmentResponse = $api->attach(
                $attachmentEndpoint,
                'attachment',
                $attachmentPath['path'],
                $attachmentPath['name'],
                ['filename' => $attachmentPath['name']],
            );

            if ($attachmentResponse->successful()) {
                $payload['data']['attachment_token'] = $attachmentResponse->json('data.attachment_token');
            }
        }

        $createEndpoint = $direction === 'issued'
            ? "/c/{$companyId}/issued_documents"
            : "/c/{$companyId}/received_documents";

        $response = $api->post($createEndpoint, $payload);

        if ($response->failed()) {
            return [
                'file' => $plan['file_name'],
                'status' => 'failed',
                'id' => null,
                'message' => $api->errorMessage($response),
            ];
        }

        return [
            'file' => $plan['file_name'],
            'status' => 'imported',
            'id' => $response->json('data.id'),
            'message' => 'OK',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     * @return array<string, mixed>
     */
    protected function jsonSummary(int|string $companyId, array $plans): array
    {
        return [
            'company_id' => $companyId,
            'dry_run' => (bool) $this->option('dry-run'),
            'documents' => $plans,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     */
    protected function renderPlanTable(array $plans): void
    {
        $rows = [];

        foreach ($plans as $plan) {
            if (in_array($plan['status'], ['ready', 'blocked'], true)) {
                $rows[] = [
                    $plan['file_name'],
                    $plan['status'],
                    $plan['summary']['api_type'] ?? '',
                    $this->ourRole($plan['summary']),
                    $this->matchedEntity($plan['summary']),
                    $this->recognizedParts($plan['summary']),
                    $plan['summary']['number'] ?? '',
                    $plan['summary']['date'] ?? '',
                    $plan['summary']['counterparty'] ?? '',
                    $plan['summary']['total'] ?? '',
                    implode(' | ', $plan['warnings']),
                ];

                continue;
            }

            $rows[] = [
                $plan['file_name'],
                'invalid',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                $plan['error'] ?? 'Invalid XML',
            ];
        }

        $this->table(['File', 'Status', 'Type', 'Our role', 'Matched entity', 'Recognition', 'Number', 'Date', 'Counterparty', 'Total', 'Notes'], $rows);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function renderSinglePlan(array $plan): void
    {
        if (! in_array($plan['status'], ['ready', 'blocked'], true)) {
            $this->error($plan['error'] ?? 'Invalid XML');

            return;
        }

        $summary = $plan['summary'];
        $isReady = $plan['status'] === 'ready';
        $statusLabel = $isReady
            ? '<fg=green;options=bold>READY</>'
            : '<fg=red;options=bold>BLOCKED</>';

        $this->newLine();
        $this->line('<options=bold>XML import recap</>');
        $this->line(str_repeat("\xe2\x94\x80", 60));
        $this->newLine();

        // -- Status banner --
        $this->line('  Status:     '.$statusLabel);
        $this->line('  Importable: '.($isReady ? '<fg=green>yes</>' : '<fg=red>no</>'));
        $this->newLine();

        // -- Document --
        $this->line('<options=bold>Document</>');
        $this->line('  File:       '.$plan['file_name']);
        $this->line('  Type:       '.($summary['api_type'] ?? '').' <fg=gray>('.($summary['document_type_code'] ?? '').')</>');
        $this->line('  Number:     <options=bold>'.($summary['number'] ?? '').'</>');
        $this->line('  Date:       '.($summary['date'] ?? ''));
        $this->line('  Total:      <options=bold>'.$this->formatTotal($summary).'</>');
        $this->newLine();

        // -- Parties --
        $this->line('<options=bold>Parties</>');
        $sellerMatch = ($summary['seller_is_company'] ?? false) ? ' <fg=green>(your company)</>' : '';
        $buyerMatch = ($summary['buyer_is_company'] ?? false) ? ' <fg=green>(your company)</>' : '';

        $this->line('  Seller:     '.($summary['seller_name'] ?? 'unknown').$sellerMatch);
        if ($summary['seller_vat'] ?? null) {
            $this->line('              VAT '.($summary['seller_vat']));
        }
        if (($summary['seller_tax_code'] ?? null) && ($summary['seller_tax_code'] !== ($summary['seller_vat'] ?? null))) {
            $this->line('              CF  '.($summary['seller_tax_code']));
        }

        $this->line('  Buyer:      '.($summary['buyer_name'] ?? 'unknown').$buyerMatch);
        if ($summary['buyer_vat'] ?? null) {
            $this->line('              VAT '.($summary['buyer_vat']));
        }
        if (($summary['buyer_tax_code'] ?? null) && ($summary['buyer_tax_code'] !== ($summary['buyer_vat'] ?? null))) {
            $this->line('              CF  '.($summary['buyer_tax_code']));
        }
        $this->newLine();

        // -- Matching --
        $this->line('<options=bold>Matching</>');
        $matchedEntityLabel = $this->matchedEntity($summary);
        $this->line('  Matched '.$this->entityKind($summary).': '.($matchedEntityLabel === 'none' ? '<fg=yellow>'.$matchedEntityLabel.'</>' : '<fg=green>'.$matchedEntityLabel.'</>'));
        $this->line('  Direction:  '.($summary['expected_direction'] ?? '<fg=yellow>unknown</>'));
        $this->newLine();

        // -- Recognition --
        $recognitionColor = match ($summary['recognition_status'] ?? 'unknown') {
            'complete' => 'green',
            'partial' => 'yellow',
            default => 'red',
        };
        $this->line('<options=bold>Recognition</> <fg='.$recognitionColor.'>('.($summary['recognition_status'] ?? 'unknown').')</>');
        $this->line('  VAT rows:        '.($summary['recognized_vat_rows'] ?? 0).'/'.($summary['total_vat_rows'] ?? 0));
        $this->line('  Withholding:     '.($summary['recognized_withholding_taxes'] ?? 0));
        $this->line('  Cassa:           '.($summary['recognized_social_security_blocks'] ?? 0));
        $this->line('  References:      '.($summary['recognized_references'] ?? 0));
        $this->line('  Attachments:     '.$this->attachmentSummary($summary));

        $warnings = $plan['warnings'] ?? [];
        $blockedReason = $plan['blocked_reason'] ?? null;

        if ($blockedReason !== null || $warnings !== []) {
            $this->newLine();
            $this->line('<fg=red;options=bold>Issues</>');

            if ($blockedReason !== null) {
                $this->line('  <fg=red>* '.$blockedReason.'</>');
            }

            foreach ($warnings as $warning) {
                if ($warning !== $blockedReason) {
                    $this->line('  <fg=yellow>* '.$warning.'</>');
                }
            }
        }

        $this->newLine();
        $this->line(str_repeat("\xe2\x94\x80", 60));
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    protected function renderResultTable(array $results): void
    {
        $rows = array_map(fn (array $result) => [
            $result['file'],
            $result['status'],
            $result['id'] ?? '',
            $result['message'],
        ], $results);

        $this->table(['File', 'Status', 'ID', 'Message'], $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     */
    protected function hasImportablePlans(array $plans): bool
    {
        return collect($plans)->contains(fn (array $plan) => $plan['status'] === 'ready');
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function ourRole(array $summary): string
    {
        if (($summary['seller_is_company'] ?? false) === true) {
            return 'seller';
        }

        if (($summary['buyer_is_company'] ?? false) === true) {
            return 'buyer';
        }

        return 'none';
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function companyRole(array $summary): string
    {
        return match ($this->ourRole($summary)) {
            'seller' => 'seller',
            'buyer' => 'buyer',
            default => 'no match',
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function matchedEntity(array $summary): string
    {
        if (! isset($summary['matched_entity_id'])) {
            return 'none';
        }

        return ($summary['matched_entity_type'] ?? 'entity').'#'.$summary['matched_entity_id'];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function entityKind(array $summary): string
    {
        return $summary['matched_entity_type'] ?? 'entity';
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function recognizedParts(array $summary): string
    {
        return implode(', ', array_filter([
            'status='.($summary['recognition_status'] ?? 'unknown'),
            isset($summary['recognized_vat_rows'], $summary['total_vat_rows'])
                ? 'vat='.$summary['recognized_vat_rows'].'/'.$summary['total_vat_rows']
                : null,
            isset($summary['recognized_withholding_taxes'])
                ? 'rit='.$summary['recognized_withholding_taxes']
                : null,
            isset($summary['recognized_social_security_blocks'])
                ? 'cassa='.$summary['recognized_social_security_blocks']
                : null,
            isset($summary['recognized_references'])
                ? 'rif='.$summary['recognized_references']
                : null,
            isset($summary['recognized_attachments'])
                ? 'all='.$summary['recognized_attachments']
                : null,
            isset($summary['expected_direction']) && $summary['expected_direction'] !== null
                ? 'xml='.$summary['expected_direction']
                : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function attachmentSummary(array $summary): string
    {
        $count = (int) ($summary['recognized_attachments'] ?? 0);

        return match (true) {
            $count === 0 => 'none',
            $count === 1 => '1 embedded attachment',
            default => $count.' embedded attachments (zip bundle)',
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function formatTotal(array $summary): string
    {
        $total = $summary['total'] ?? null;

        if ($total === null) {
            return '-';
        }

        $currency = $summary['currency'] ?? 'EUR';

        return number_format((float) $total, 2, '.', ',').' '.$currency;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{name: string, path: string}|null
     */
    protected function prepareAttachmentUpload(array $plan): ?array
    {
        $attachments = $plan['attachments'] ?? [];

        if (is_array($attachments) && count($attachments) > 0) {
            if (count($attachments) === 1) {
                $attachment = $attachments[0];
                $content = base64_decode((string) ($attachment['content_base64'] ?? ''), true);

                if ($content === false) {
                    return null;
                }

                $path = tempnam(sys_get_temp_dir(), 'fic-attachment-');

                if ($path === false) {
                    return null;
                }

                file_put_contents($path, $content);

                return [
                    'name' => (string) ($attachment['name'] ?? 'attachment.bin'),
                    'path' => $path,
                ];
            }

            $zipPath = tempnam(sys_get_temp_dir(), 'fic-attachments-');

            if ($zipPath === false) {
                return null;
            }

            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
                return null;
            }

            foreach ($attachments as $index => $attachment) {
                $content = base64_decode((string) ($attachment['content_base64'] ?? ''), true);

                if ($content === false) {
                    continue;
                }

                $zip->addFromString((string) ($attachment['name'] ?? 'attachment-'.($index + 1).'.bin'), $content);
            }

            $zip->close();

            return [
                'name' => pathinfo($plan['file_name'], PATHINFO_FILENAME).'-attachments.zip',
                'path' => $zipPath,
            ];
        }

        return null;
    }
}

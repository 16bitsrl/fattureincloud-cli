<?php

namespace App\Services;

class OAuthScopes
{
    /**
     * Every scope fic can request, as read (:r) / full access (:a) pairs.
     */
    public const RESOURCES = [
        'entity.clients', 'entity.suppliers', 'products',
        'issued_documents.invoices', 'issued_documents.credit_notes', 'issued_documents.receipts',
        'issued_documents.orders', 'issued_documents.quotes', 'issued_documents.proformas',
        'issued_documents.delivery_notes', 'issued_documents.work_reports',
        'issued_documents.supplier_orders', 'issued_documents.self_invoices',
        'received_documents', 'receipts', 'taxes', 'archive', 'cashbook', 'settings',
        'calendar', 'stock',
    ];

    /**
     * Resources that only have a read scope.
     */
    public const READ_ONLY_RESOURCES = ['situation', 'emails'];

    /**
     * Issued document `type` values and the scope resource that covers them.
     */
    protected const DOCUMENT_TYPES = [
        'invoice' => 'issued_documents.invoices',
        'credit_note' => 'issued_documents.credit_notes',
        'receipt' => 'issued_documents.receipts',
        'order' => 'issued_documents.orders',
        'quote' => 'issued_documents.quotes',
        'proforma' => 'issued_documents.proformas',
        'delivery_note' => 'issued_documents.delivery_notes',
        'work_report' => 'issued_documents.work_reports',
        'supplier_order' => 'issued_documents.supplier_orders',
        'self_own_invoice' => 'issued_documents.self_invoices',
        'self_supplier_invoice' => 'issued_documents.self_invoices',
    ];

    /**
     * @return list<string>
     */
    public static function readOnly(): array
    {
        return [
            ...array_map(fn ($resource) => "{$resource}:r", self::RESOURCES),
            ...array_map(fn ($resource) => "{$resource}:r", self::READ_ONLY_RESOURCES),
        ];
    }

    /**
     * @return list<string>
     */
    public static function full(): array
    {
        return [
            ...array_map(fn ($resource) => "{$resource}:a", self::RESOURCES),
            ...array_map(fn ($resource) => "{$resource}:r", self::READ_ONLY_RESOURCES),
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique([...self::readOnly(), ...self::full()]));
    }

    /**
     * Merge scope lists, dropping a read scope when full access to the same
     * resource is also present (full access covers reading).
     *
     * @return list<string>
     */
    public static function merge(array ...$lists): array
    {
        $scopes = array_unique(array_merge(...$lists));

        return array_values(array_filter(
            $scopes,
            fn (string $scope) => ! (str_ends_with($scope, ':r') && in_array(substr($scope, 0, -2).':a', $scopes, true)),
        ));
    }

    public static function isGranted(string $scope, array $granted): bool
    {
        return in_array($scope, $granted, true)
            || (str_ends_with($scope, ':r') && in_array(substr($scope, 0, -2).':a', $granted, true));
    }

    /**
     * Scopes that can authorize an operation: any one of them is enough.
     *
     * The spec lists alternatives (e.g. one scope per issued document type);
     * when the document type is known only its scope applies.
     *
     * @return list<string>
     */
    public static function required(array $operationData, ?string $documentType = null): array
    {
        $scopes = [];

        foreach ($operationData['security'] ?? [] as $requirement) {
            if (isset($requirement['OAuth2AuthenticationCodeFlow'])) {
                $scopes = $requirement['OAuth2AuthenticationCodeFlow'];
            }
        }

        $scopes = self::withUnlistedIssuedDocumentScopes(array_values($scopes));

        $resource = self::DOCUMENT_TYPES[$documentType] ?? null;

        if ($resource !== null) {
            $matching = array_values(array_filter($scopes, fn ($scope) => str_starts_with($scope, "{$resource}:")));

            if ($matching !== []) {
                return $matching;
            }
        }

        return array_values($scopes);
    }

    /**
     * The spec `security` blocks of issued document operations list only some
     * document types: add the other issued document scopes, at the same access
     * levels, so self-invoices, work reports and supplier orders are covered.
     *
     * @param  list<string>  $scopes
     * @return list<string>
     */
    protected static function withUnlistedIssuedDocumentScopes(array $scopes): array
    {
        $levels = [];

        foreach ($scopes as $scope) {
            if (str_starts_with($scope, 'issued_documents.')) {
                $levels[substr($scope, -1)] = true;
            }
        }

        foreach (array_keys($levels) as $level) {
            foreach (array_unique(self::DOCUMENT_TYPES) as $resource) {
                $scopes[] = "{$resource}:{$level}";
            }
        }

        return array_values(array_unique($scopes));
    }

    /**
     * Scopes to suggest when none of the required ones is granted, or [] when
     * the operation is allowed.
     *
     * @return list<string>
     */
    public static function missing(array $required, array $granted): array
    {
        foreach ($required as $scope) {
            if (self::isGranted($scope, $granted)) {
                return [];
            }
        }

        return $required;
    }
}

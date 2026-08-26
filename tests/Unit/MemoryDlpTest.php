<?php

namespace Tests\Unit;

use App\Services\MemoryDlp;
use Tests\TestCase;

class MemoryDlpTest extends TestCase
{
    public function test_it_scans_nested_values_and_metadata_keys(): void
    {
        $this->assertFalse(MemoryDlp::containsSecretInMemoryPayload([
            'content' => 'Eine normale bestätigte Präferenz.',
            'meta' => ['nested' => ['language' => 'de']],
            'tags' => ['preference'],
        ]));
        $this->assertTrue(MemoryDlp::containsSecretInMemoryPayload([
            'content' => 'Eine normale bestätigte Präferenz.',
            'meta' => ['api_key' => 'github_pat_abcdefghijklmnopqrstuvwxyz123456'],
        ]));
    }

    public function test_it_canonicalizes_snake_and_camel_case_secret_keys(): void
    {
        foreach (['password_hash', 'passwordHash', 'dbPassword', 'secret_value', 'clientSecret', 'APIKey'] as $key) {
            $this->assertTrue(
                MemoryDlp::containsSecretInMemoryPayload([
                    'content' => 'Unauffälliger Inhalt.',
                    'meta' => ['nested' => [$key => 'opaque-value']],
                ]),
                "Expected {$key} to be treated as a sensitive metadata key.",
            );
        }

        $this->assertFalse(MemoryDlp::containsSecretInMemoryPayload([
            'content' => 'Tokenanzahl ohne Zugangsdaten.',
            'meta' => ['token_count' => 128, 'secretary_name' => 'Beispiel'],
        ]));
    }

    public function test_it_fails_closed_for_cycles_depth_node_count_and_size_limits(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;

        $deep = ['value' => 'safe'];
        for ($index = 0; $index < 12; $index++) {
            $deep = ['nested' => $deep];
        }

        $this->assertTrue(MemoryDlp::containsSecret($recursive));
        $this->assertTrue(MemoryDlp::containsSecret($deep));
        $this->assertTrue(MemoryDlp::containsSecret(array_fill(0, 1100, 'safe')));
        $this->assertTrue(MemoryDlp::containsSecret(str_repeat('x', 65537)));
    }

    public function test_local_repository_origin_is_detected_at_nested_policy_boundaries(): void
    {
        $this->assertFalse(MemoryDlp::containsLocalOnlySourceInMemoryPayload([
            'source_type' => 'user',
            'meta' => ['language' => 'de'],
        ]));
        $this->assertTrue(MemoryDlp::containsLocalOnlySourceInMemoryPayload([
            'source_type' => 'user',
            'meta' => ['origin' => ['sourceType' => 'repository-graph']],
        ]));
        $this->assertTrue(MemoryDlp::containsLocalOnlySourceInMemoryPayload([
            'source_type' => 'user',
            'provenance' => ['source' => ['type' => 'raw_code']],
        ]));
    }

    public function test_only_plain_language_queries_are_allowed_to_reach_the_semantic_provider(): void
    {
        $this->assertTrue(MemoryDlp::allowsExternalSemanticQuery('Welche Architekturentscheidung gilt für das Projekt?'));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticQuery('Architektur sk-proj-abcdefghijklmnopqrstuvwxyz'));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticQuery('Welche Erinnerung gehört max.mustermann@example.com?'));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticQuery('Was weißt du über +49 171 1234567?'));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticQuery('Rufe 0171 1234567 zurück.'));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticQuery('Bitte merke dir DE89370400440532013000.'));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticQuery('Bitte merke dir 4111 1111 1111 1111.'));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticQuery("function leakSecret() {\n    return config('app.key');\n}"));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticQuery('Prüfe C:\\Users\\alice\\repo\\src\\Memory.php:42'));
    }

    public function test_direct_identifiers_and_sensitive_content_never_reach_external_semantic_storage(): void
    {
        $this->assertTrue(MemoryDlp::allowsExternalSemanticContent([
            'content' => 'Die Oberfläche soll standardmäßig Deutsch verwenden.',
            'sensitivity' => 'normal',
            'source_type' => 'user',
        ]));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticContent([
            'content' => 'Kontakt max.mustermann@example.com, Telefon +49 171 1234567.',
            'sensitivity' => 'normal',
            'source_type' => 'user',
        ]));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticContent([
            'content' => 'Kontonummer DE89 3704 0044 0532 0130 00',
            'sensitivity' => 'normal',
            'source_type' => 'user',
        ]));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticContent([
            'content' => 'Zahlungskarte 4111-1111-1111-1111',
            'sensitivity' => 'normal',
            'source_type' => 'user',
        ]));
        $this->assertFalse(MemoryDlp::allowsExternalSemanticContent([
            'content' => 'Eine intern bestätigte Personalentscheidung.',
            'sensitivity' => 'sensitive',
            'source_type' => 'user',
        ]));
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Ynov\Public\ProposititionController;
use App\Models\Api\Ynov\Esouscription\Acteur;
use App\Models\Api\Ynov\Esouscription\Contrat;
use App\Models\Api\Ynov\Esouscription\ContratActeur;
use App\Services\Api\Ynov\Documents\DocumentService;
use App\Services\Api\Ynov\ESouscription\ActeurService;
use App\Services\Api\Ynov\ESouscription\AssurerGarantieService;
use App\Services\Api\Ynov\ESouscription\ClientNumberGenerator;
use App\Services\Api\Ynov\ESouscription\ContratActeurService;
use App\Services\Api\Ynov\ESouscription\ContratService;
use App\Services\Api\Ynov\ESouscription\DeclarationSanteService;
use App\Services\Api\Ynov\ESouscription\PropositionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PartnerSouscriptionApiTest extends TestCase
{
    public function test_partner_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/v1/partners/souscriptions', [])
            ->assertUnauthorized();
    }

    public function test_user_without_partner_assignment_cannot_create_a_subscription(): void
    {
        $user = new class {
            public string $uuid_user = 'user-uuid';
            public ?string $partner_uuid = null;

            public function primaryAgence(): object
            {
                return (object) ['uuid_agence' => 'agency-uuid'];
            }
        };
        $request = Request::create('/api/v1/partners/souscriptions', 'POST');
        $request->setUserResolver(fn () => $user);

        $controller = new ProposititionController(
            Mockery::mock(ClientNumberGenerator::class),
            Mockery::mock(ActeurService::class),
            Mockery::mock(ContratActeurService::class),
            Mockery::mock(DeclarationSanteService::class),
            Mockery::mock(DocumentService::class),
            Mockery::mock(ContratService::class),
            Mockery::mock(AssurerGarantieService::class),
            Mockery::mock(PropositionService::class)
        );

        $response = $controller->storeApiSouscription($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(422, $response->getData(true)['code']);
    }

    public function test_document_storage_failure_does_not_prevent_contract_creation(): void
    {
        $user = new class {
            public string $uuid_user = 'user-uuid';
            public string $partner_uuid = 'partner-uuid';

            public function primaryAgence(): object
            {
                return (object) ['uuid_agence' => 'agency-uuid'];
            }
        };

        $request = Request::create('/api/v1/partners/souscriptions', 'POST', [
            'adherentData' => [
                'genre' => 1,
                'civilite' => 'M',
                'nom' => 'Test',
                'prenoms' => 'User',
                'date_naissance' => '1990-01-01',
                'lieunaissance_code' => '000',
                'email' => 'test@example.com',
                'mobile' => '00000000',
                'telephone' => '00000000',
                'numero_piece' => 'ABC123',
                'nni' => '123456789',
                'nature_piece' => 'CNI',
                'situation_matrimoniale' => 'C',
                'profession_code' => '000',
                'employeur' => 'Test',
                'lieuresidence_code' => '000',
                'pays_code' => '000',
            ],
            'documentDatas' => [['libelle' => 'Pièce d’identité - recto']],
        ]);
        $request->setUserResolver(fn () => $user);

        $clientNumberGenerator = Mockery::mock(ClientNumberGenerator::class);
        $clientNumberGenerator->shouldReceive('generate')->once()->andReturn('client-number');

        $acteurService = Mockery::mock(ActeurService::class);
        $acteurService->shouldReceive('create')->once()->andReturn(
            (new Acteur())->forceFill(['uuid_acteur' => 'actor-uuid'])
        );

        $contratActeurService = Mockery::mock(ContratActeurService::class);
        $contratActeurService->shouldReceive('create')->once()->andReturn(new ContratActeur());

        $documentService = Mockery::mock(DocumentService::class);
        $documentService->shouldReceive('createDocument')->once()->andThrow(new \RuntimeException('WebDAV unavailable'));

        $contratService = Mockery::mock(ContratService::class);
        $contratService->shouldReceive('create')->once()->andReturn(
            (new Contrat())->forceFill(['uuid_contrat' => 'contract-uuid'])
        );

        DB::shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn ($callback) => $callback());

        $controller = new ProposititionController(
            $clientNumberGenerator,
            $acteurService,
            $contratActeurService,
            Mockery::mock(DeclarationSanteService::class),
            $documentService,
            $contratService,
            Mockery::mock(AssurerGarantieService::class),
            Mockery::mock(PropositionService::class)
        );

        $response = $controller->storeApiSouscription($request);
        $responseData = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($responseData['success']);
        $this->assertSame('contract-uuid', $responseData['contratData']['uuid_contrat']);
        $this->assertSame([], $responseData['documentStore']);
        $this->assertNotNull($responseData['documentWarning']);
    }
}

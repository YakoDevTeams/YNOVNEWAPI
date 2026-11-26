<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Ynov\Esouscription\ContratController;
use App\Services\Api\Ynov\ESouscription\ContratService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ContratIndexTest extends TestCase
{
    public function testIndexForwardsEtapeFilterIncludingZero(): void
    {
        $service = Mockery::mock(ContratService::class);
        $service->shouldReceive('getAll')
            ->once()
            ->with(Mockery::on(fn (array $received) => $received['etape'] === '0'), 20)
            ->andReturn(new LengthAwarePaginator([], 0, 20));

        $response = (new ContratController($service))->index(
            Request::create('/api/v1/esouscription/get-contrats?etape=0&per_page=20', 'GET')
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexRejectsInvalidEtape(): void
    {
        $service = Mockery::mock(ContratService::class);
        $service->shouldNotReceive('getAll');

        $this->expectException(ValidationException::class);

        (new ContratController($service))->index(
            Request::create('/api/v1/esouscription/get-contrats?etape=5', 'GET')
        );
    }
}

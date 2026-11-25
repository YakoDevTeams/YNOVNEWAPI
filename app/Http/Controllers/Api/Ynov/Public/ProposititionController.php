<?php

namespace App\Http\Controllers\Api\Ynov\Public;

use App\Http\Controllers\Controller;
use App\Models\Api\Ynov\Esouscription\Contrat;
use App\Services\Api\Ynov\Documents\DocumentService;
use App\Services\Api\Ynov\ESouscription\ActeurService;
use App\Services\Api\Ynov\ESouscription\AssurerGarantieService;
use App\Services\Api\Ynov\ESouscription\ClientNumberGenerator;
use App\Services\Api\Ynov\ESouscription\ContratActeurService;
use App\Services\Api\Ynov\ESouscription\ContratService;
use App\Services\Api\Ynov\ESouscription\DeclarationSanteService;
use App\Services\Api\Ynov\ESouscription\PropositionService;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProposititionController extends Controller
{
    public function __construct(
        private ClientNumberGenerator $clientNumberGenerator,
        private ActeurService $acteurService,
        private ContratActeurService $contratActeurService,
        private DeclarationSanteService $declarationSanteService,
        private DocumentService $documentService,
        private ContratService $contratService,
        private AssurerGarantieService $assurerGarantieService,
        private PropositionService $propositionService
    ) {}
    
    public function storeApiSouscription(Request $request, ?string $draftUuid = null)
    {
        $authenticatedUser = $request->user();
        $userUuid = $authenticatedUser?->uuid_user;
        if (!$userUuid) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.',
                'code' => 401,
            ], 401);
        }

        $partnerUuid = $authenticatedUser->partner_uuid;
        $agenceUuid = $authenticatedUser->primaryAgence()?->uuid_agence;
        if (!$partnerUuid || !$agenceUuid) {
            return response()->json([
                'success' => false,
                'message' => 'Votre compte doit être associé à un partenaire et à une agence principale active.',
                'code' => 422,
            ], 422);
        }

        $request->validate([
            'adherentData' => ['required', 'array'],
            'assurerDatas' => ['sometimes', 'array'],
            'BeneficiaireDatas' => ['sometimes', 'array'],
            'documentDatas' => ['sometimes', 'array'],
            'contratData' => ['sometimes', 'array'],
        ]);

        try {
            $result = DB::transaction(function () use ($request, $draftUuid, $userUuid, $partnerUuid, $agenceUuid) {

                if ($draftUuid !== null) {
                    $accessibleDraft = $this->propositionService->findAccessibleByUuid(
                        $userUuid,
                        $draftUuid
                    );
                    $draft = Contrat::query()->lockForUpdate()->findOrFail($accessibleDraft->uuid_contrat);
                } else {
                    $draft = null;
                }

                if ($draft && (int) $draft->etape !== 0) {
                    throw ValidationException::withMessages([
                        'uuid' => 'Ce contrat n’est plus un brouillon et ne peut pas être complété ici.',
                    ]);
                }

                $contratUuid = $draft?->uuid_contrat ?? Str::uuid()->toString();
                $keyIntegration = now()->format('Ymdh');


                $adherentData      = $request->adherentData ?? [];
                $assurerDatas      = $request->assurerDatas ?? [];
                $BeneficiaireDatas = $request->BeneficiaireDatas ?? [];
                $DocumentDatas     = $request->documentDatas ?? [];
                $contratData       = $request->contratData ?? [];

                if ($draft) {
                    $contratData = array_merge([
                        'code_produit' => $draft->code_produit,
                        'libelle_produit' => $draft->libelle_produit,
                        'formule_produit_code' => $draft->formule_produit_code,
                        'etape' => 1,
                    ], $contratData);
                    $contratData['etape'] = 1;
                }

                $createdBy = $userUuid;

                if (isset($contratData['etape']) && $contratData['etape'] !== '') {

                    if ($contratData['etape'] == '3') {

                        $dateTransmission = now()->format('Y-m-d H:i:s');
                        $transmisPar = $createdBy;
                        $dateAcceptee = now()->format('Y-m-d H:i:s');
                        $accepted_by = $createdBy;

                    } elseif ($contratData['etape'] == '2') {

                        $dateTransmission = now()->format('Y-m-d H:i:s');
                        $transmisPar = $createdBy;

                    } else {

                        $dateTransmission = null;
                        $transmisPar = null;
                        $dateAcceptee = null;
                        $accepted_by = null;
                    }
                }
                

                // ==================== 1. Souscripteur (ADH) ====================
                $numeroClient = $this->clientNumberGenerator->generate(
                    (int) $adherentData['genre'],
                    new DateTimeImmutable($adherentData['date_naissance']),
                );

                $adherentStore = $this->acteurService->create([
                    'uuid_acteur'            => Str::uuid(),
                    'idClient'               => $numeroClient,
                    'civilite'               => $adherentData['civilite'],
                    'genre'                  => $adherentData['genre'],
                    'nom'                    => $adherentData['nom'],
                    'prenoms'                => $adherentData['prenoms'],
                    'date_naissance'         => $adherentData['date_naissance'],
                    'lieunaissance_code'     => $adherentData['lieunaissance_code'],
                    'email'                  => $adherentData['email'],
                    'mobile'                 => $adherentData['mobile'],
                    'telephone'              => $adherentData['telephone'],
                    'numero_piece'           => $adherentData['numero_piece'],
                    'nni'                    => $adherentData['nni'],
                    'nature_piece'           => $adherentData['nature_piece'],
                    'situation_matrimoniale' => $adherentData['situation_matrimoniale'],
                    'profession_code'        => $adherentData['profession_code'],
                    'employeur'              => $adherentData['employeur'],
                    'lieuresidence_code'     => $adherentData['lieuresidence_code'],
                    'pays_code'              => $adherentData['pays_code'],
                    'integration_key'        => $keyIntegration,
                    'created_by'             => $createdBy,
                ]);

                if (!$adherentStore) {
                    throw new \Exception("Échec de la création du souscripteur.");
                }

                $contratAdherentStore = $this->contratActeurService->create([
                    'uuid_contrat_acteur' => Str::uuid(),
                    'contrat_uuid'        => $contratUuid,
                    'acteur_uuid'         => $adherentStore->uuid_acteur,
                    'filiation_uuid'      => 'LUI-MEME',
                    'type_acteur'         => 'ADH',
                    'integration_key'     => $keyIntegration,
                    'created_by'          => $createdBy,
                ]);

                if (!$contratAdherentStore) {
                    throw new \Exception("Échec de la liaison contrat-adhérent.");
                }

                Log::info("Souscripteur créé avec succès : {$adherentStore->uuid_acteur}");

                // ==================== 2. Assurés (ASS) ====================
                foreach ($assurerDatas as $assure) {

                    $assureInfo  = $assure['info']  ?? [];
                    $assureSante = $assure['sante'] ?? [];
                    $assureGaranties = data_get($assure, 'garanties.garantieData')
                        ?? data_get($assure, 'garantie.garantieData')
                        ?? ($assure['garanties'] ?? $assure['garantie'] ?? []);
                    if (isset($assureGaranties['garantieData'])) {
                        $assureGaranties = $assureGaranties['garantieData'];
                    }
                    if (
                        is_array($assureGaranties)
                        && (
                            array_key_exists('garantieObligatoire', $assureGaranties)
                            || array_key_exists('garantieOptionnel', $assureGaranties)
                        )
                    ) {
                        $garantiesObligatoires = $assureGaranties['garantieObligatoire'] ?? [];
                        $garantiesOptionnelles = $assureGaranties['garantieOptionnel'] ?? [];
                        if (!is_array($garantiesObligatoires) || !is_array($garantiesOptionnelles)) {
                            throw new \InvalidArgumentException('Les garanties de l’assuré doivent être des listes.');
                        }
                        $assureGaranties = array_merge($garantiesObligatoires, $garantiesOptionnelles);
                    }
                    if (isset($assureGaranties['codeGarantie'])) {
                        $assureGaranties = [$assureGaranties];
                    }
                    if (!is_array($assureGaranties)) {
                        throw new \InvalidArgumentException('Les garanties de l’assuré doivent être une liste.');
                    }
                    $assureCreatedBy = $createdBy;
                    $isAdherentAssure = data_get($assure, 'is_adherent') === 'oui';
                    $uuidAssure = $isAdherentAssure
                        ? $adherentStore->uuid_acteur
                        : Str::uuid();

                    if ($isAdherentAssure) {

                        $contratAssureAdherentStore = $this->contratActeurService->create([
                            'uuid_contrat_acteur' => Str::uuid(),
                            'contrat_uuid'        => $contratUuid,
                            'acteur_uuid'         => $adherentStore->uuid_acteur,
                            'filiation_uuid'      => 'LUI-MEME',
                            'type_acteur'         => 'ASS',
                            'integration_key'     => $keyIntegration,
                            'created_by'          => $assureCreatedBy,
                        ]);

                        if (!$contratAssureAdherentStore) {
                            throw new \Exception("Échec de la liaison contrat-assuré : {$assureInfo['nom']}");
                        }

                        $AssuAdhStoreSante = $this->declarationSanteService->create([
                            'uuid_declaration_santes'                  => Str::uuid(),
                            'contrat_uuid'                             => $contratUuid,
                            'assure_uuid'                              => $uuidAssure,
                            'taille'                                   => $assureSante['taille'],
                            'poids'                                    => $assureSante['poids'],
                            'tension_min'                              => $assureSante['tension_min'],
                            'tension_max'                              => $assureSante['tension_max'],
                            'tabagisme'                                => $assureSante['tabagisme'],
                            'alcool'                                   => $assureSante['alcool'],
                            'sport'                                    => $assureSante['sport'],
                            'accident'                                 => $assureSante['accident'],
                            'traitement'                               => $assureSante['traitement'],
                            'transfusion_sanguine'                     => $assureSante['transfusion_sanguine'],
                            'intervention_chirurgicale'                => $assureSante['intervention_chirurgicale'],
                            'prochaine_intervention_chirurgicale'      => $assureSante['prochaine_intervention_chirurgicale'],
                            'diabete'                                  => $assureSante['diabete'],
                            'hypertension'                             => $assureSante['hypertension'],
                            'drepanocytose'                            => $assureSante['drepanocytose'],
                            'cirrhose_foie'                            => $assureSante['cirrhose_foie'],
                            'maladie_pulmonaire'                       => $assureSante['maladie_pulmonaire'],
                            'cancer'                                   => $assureSante['cancer'],
                            'anemie'                                   => $assureSante['anemie'],
                            'insuffisance_renale'                      => $assureSante['insuffisance_renale'],
                            'avc'                                      => $assureSante['avc'],
                            'integration_key'                          => $keyIntegration,
                            'created_by'                               => $assureCreatedBy,
                        ]);

                        if (!$AssuAdhStoreSante) {
                            throw new \Exception("Échec de la déclaration santé de l'assuré : {$assureInfo['nom']}");
                        }

                    } else {

                        $assurerStore = $this->acteurService->create([
                            'uuid_acteur'            => $uuidAssure,
                            'civilite'               => $assureInfo['civilite'],
                            'genre'                  => $assureInfo['genre'],
                            'nom'                    => $assureInfo['nom'],
                            'prenoms'                => $assureInfo['prenoms'],
                            'date_naissance'         => $assureInfo['date_naissance'],
                            'lieunaissance_code'     => $assureInfo['lieunaissance_code'],
                            'email'                  => $assureInfo['email'],
                            'mobile'                 => $assureInfo['mobile'],
                            'telephone'              => $assureInfo['telephone'],
                            'numero_piece'           => $assureInfo['numero_piece'],
                            'nni'                    => $assureInfo['nni'],
                            'nature_piece'           => $assureInfo['nature_piece'],
                            'situation_matrimoniale' => $assureInfo['situation_matrimoniale'],
                            'profession_code'        => $assureInfo['profession_code'],
                            'employeur'              => $assureInfo['employeur'],
                            'lieuresidence_code'     => $assureInfo['lieuresidence_code'],
                            'pays_code'              => $assureInfo['pays_code'],
                            'integration_key'        => $keyIntegration,
                            'created_by'             => $assureCreatedBy,
                        ]);

                        if (!$assurerStore) {
                            throw new \Exception("Échec de la création de l'assuré : {$assureInfo['nom']}");
                        }

                        $contratAssureStore = $this->contratActeurService->create([
                            'uuid_contrat_acteur' => Str::uuid(),
                            'contrat_uuid'        => $contratUuid,
                            'acteur_uuid'         => $uuidAssure,
                            'filiation_uuid'      => $assureInfo['filiation_uuid'] ?? null,
                            'type_acteur'         => 'ASS',
                            'integration_key'     => $keyIntegration,
                            'created_by'          => $assureCreatedBy,
                        ]);

                        if (!$contratAssureStore) {
                            throw new \Exception("Échec de la liaison contrat-assuré : {$assureInfo['nom']}");
                        }

                        $assurSanteStore = $this->declarationSanteService->create([
                            'uuid_declaration_santes'                  => Str::uuid(),
                            'contrat_uuid'                             => $contratUuid,
                            'assure_uuid'                              => $uuidAssure,
                            'taille'                                   => $assureSante['taille'],
                            'poids'                                    => $assureSante['poids'],
                            'tension_min'                              => $assureSante['tension_min'],
                            'tension_max'                              => $assureSante['tension_max'],
                            'tabagisme'                                => $assureSante['tabagisme'],
                            'alcool'                                   => $assureSante['alcool'],
                            'sport'                                    => $assureSante['sport'],
                            'accident'                                 => $assureSante['accident'],
                            'traitement'                               => $assureSante['traitement'],
                            'transfusion_sanguine'                     => $assureSante['transfusion_sanguine'],
                            'intervention_chirurgicale'                => $assureSante['intervention_chirurgicale'],
                            'prochaine_intervention_chirurgicale'      => $assureSante['prochaine_intervention_chirurgicale'],
                            'diabete'                                  => $assureSante['diabete'],
                            'hypertension'                             => $assureSante['hypertension'],
                            'drepanocytose'                            => $assureSante['drepanocytose'],
                            'cirrhose_foie'                            => $assureSante['cirrhose_foie'],
                            'maladie_pulmonaire'                       => $assureSante['maladie_pulmonaire'],
                            'cancer'                                   => $assureSante['cancer'],
                            'anemie'                                   => $assureSante['anemie'],
                            'insuffisance_renale'                      => $assureSante['insuffisance_renale'],
                            'avc'                                      => $assureSante['avc'],
                            'integration_key'                          => $keyIntegration,
                            'created_by'                               => $assureCreatedBy,
                        ]);

                        if (!$assurSanteStore) {
                            throw new \Exception("Échec de la déclaration santé de l'assuré : {$assureInfo['nom']}");
                        }
                    }

                    foreach ($assureGaranties as $assureGarantie) {
                        if (
                            !is_array($assureGarantie)
                            || !isset(
                                $assureGarantie['uuid_garantie'],
                                $assureGarantie['codeGarantie'],
                                $assureGarantie['libelle'],
                                $assureGarantie['capital'],
                                $assureGarantie['prime']
                            )
                        ) {
                            throw new \InvalidArgumentException('Une garantie de l’assuré est incomplète.');
                        }

                        $this->assurerGarantieService->create([
                            'code'          => $assureGarantie['codeGarantie'],
                            'uuid_garantie' => $assureGarantie['uuid_garantie'],
                            'uuid_assurer'  => $uuidAssure,
                            'uuid_contrat'  => $contratUuid,
                            'libelle'       => $assureGarantie['libelle'],
                            'capital'       => $assureGarantie['capital'],
                            'prime'         => $assureGarantie['prime'],
                            'type'         => "ASS",
                        ]);
                    }
                }

                // ==================== 3. Bénéficiaires (BEN) ====================
                foreach ($BeneficiaireDatas as $beneficiaire) {
                    $benefUuid = Str::uuid();
                    $benefCreatedBy = $createdBy;

                    if (data_get($beneficiaire, 'is_adherent') === 'oui') {
                         $contratBenefAdhStore = $this->contratActeurService->create([
                            'uuid_contrat_acteur' => Str::uuid(),
                            'contrat_uuid'        => $contratUuid,
                            'acteur_uuid'         => $adherentStore->uuid_acteur,
                            'type_acteur'         => 'BEN',
                            'filiation_uuid'      => $beneficiaire['filiation_uuid'] ?? null,
                            'integration_key'     => $keyIntegration,
                            'created_by'          => $benefCreatedBy,
                        ]);

                        if (!$contratBenefAdhStore) {
                            throw new \Exception("Échec de la liaison contrat-bénéficiaire : {$beneficiaire['nom']}");
                        }

                    } else {

                        $beneficiaireStore = $this->acteurService->create([
                            'uuid_acteur'            => $benefUuid,
                            'civilite'               => $beneficiaire['civilite'],
                            'genre'                  => $beneficiaire['genre'],
                            'nom'                    => $beneficiaire['nom'],
                            'prenoms'                => $beneficiaire['prenoms'],
                            'date_naissance'         => $beneficiaire['date_naissance'],
                            'lieunaissance_code'     => $beneficiaire['lieunaissance_code'],
                            'email'                  => $beneficiaire['email'],
                            'mobile'                 => $beneficiaire['mobile'],
                            'telephone'              => $beneficiaire['telephone'],
                            'numero_piece'           => $beneficiaire['numero_piece'],
                            'nni'                    => $beneficiaire['nni'],
                            'nature_piece'           => $beneficiaire['nature_piece'],
                            'situation_matrimoniale' => $beneficiaire['situation_matrimoniale'],
                            'profession_code'        => $beneficiaire['profession_code'],
                            'employeur'              => $beneficiaire['employeur'],
                            'lieuresidence_code'     => $beneficiaire['lieuresidence_code'],
                            'pays_code'              => $beneficiaire['pays_code'],
                            'integration_key'        => $keyIntegration,
                            'created_by'             => $benefCreatedBy,
                        ]);

                        if (!$beneficiaireStore) {
                            throw new \Exception("Échec de la création du bénéficiaire : {$beneficiaire['nom']}");
                        }

                        $contratBenefStore = $this->contratActeurService->create([
                            'uuid_contrat_acteur' => Str::uuid(),
                            'contrat_uuid'        => $contratUuid,
                            'acteur_uuid'         => $benefUuid,
                            'type_acteur'         => 'BEN',
                            'filiation_uuid'      => $beneficiaire['filiation_uuid'] ?? null,
                            'integration_key'     => $keyIntegration,
                            'created_by'          => $benefCreatedBy,
                        ]);

                        if (!$contratBenefStore) {
                            throw new \Exception("Échec de la liaison contrat-bénéficiaire : {$beneficiaire['nom']}");
                        }
                    }
                }

                // ==================== 4. Documents ====================
                $payload = [
                    'reference_uuid' => $contratUuid,
                    'source'         => 'E-SOUSCRIPTION',
                    'created_by'     => $createdBy,
                    'documents'      => $DocumentDatas,
                ];

                $documentStore = [];
                $documentWarning = null;
                $documentError = null;

                if ($DocumentDatas === []) {
                    $documentWarning = 'Aucun document n’a été fourni. La souscription a été créée sans pièces jointes.';
                } else {
                    try {
                        $documentStore = $this->documentService->createDocument($payload);
                        if (!$documentStore) {
                            $documentStore = [];
                            $documentWarning = 'Les documents n’ont pas pu être enregistrés. La souscription a été créée sans pièces jointes.';
                        }
                    } catch (\Throwable $exception) {
                        $documentStore = [];
                        $documentWarning = 'Les documents n’ont pas pu être enregistrés. La souscription a été créée sans pièces jointes.';
                        $documentError = $exception->getMessage();
                    }
                }

                if ($documentWarning !== null) {
                    Log::warning('Souscription créée sans toutes ses pièces jointes.', [
                        'reference_uuid' => $contratUuid,
                        'user_uuid' => $createdBy,
                        'document_count' => count($DocumentDatas),
                        'warning' => $documentWarning,
                        'error' => $documentError,
                    ]);
                }

                // ==================== 5. Contrat ====================
                $contratPayload = [
                    'uuid_contrat'                  => $contratUuid,
                    'date_effet'                    => $contratData['date_effet'] ?? null,
                    'mode_paiement'                 => $contratData['mode_paiement'] ?? null,
                    'organisme'                     => $contratData['organisme'] ?? null,
                    'duree'                         => $contratData['duree'] ?? null,
                    'code_periodicite'              => $contratData['code_periodicite'] ?? null,
                    'prime'                         => $contratData['prime'] ?? null,
                    'prime_principale'              => $contratData['prime_principale'] ?? null,
                    'sur_prime'                     => $contratData['sur_prime'] ?? null,
                    'capital'                       => $contratData['capital'] ?? null,
                    'frais_adhesion'                => $contratData['frais_adhesion'] ?? null,
                    'montant_rente'                 => $contratData['montant_rente'] ?? null,
                    'periodicite_rente'             => $contratData['periodicite_rente'] ?? null,
                    'duree_rente'                   => $contratData['duree_rente'] ?? null,
                    'code_banque'                   => $contratData['code_banque'] ?? null,
                    'code_guichet'                  => $contratData['code_guichet'] ?? null,
                    'rib'                           => $contratData['rib'] ?? null,
                    'numero_compte'                 => $contratData['numero_compte'] ?? null,
                    'numecompte_complet'            => $contratData['numecompte_complet'] ?? null,
                    'autre_ref_prelev'              => $contratData['autre_ref_prelev'] ?? null,
                    'agence_uuid'                   => $agenceUuid,
                    'code_produit'                  => $contratData['code_produit'] ?? null,
                    'libelle_produit'               => $contratData['libelle_produit'] ?? null,
                    'etape'                         => $contratData['etape'] ?? null,
                    'formule_produit_code'          => $contratData['formule_produit_code'] ?? null,
                    'contact_personne_nom'          => $contratData['contact_personne_nom'] ?? null,
                    'contact_personne_mobile'       => $contratData['contact_personne_mobile'] ?? null,
                    'contact_personne_nom_2'        => $contratData['contact_personne_nom_2'] ?? null,
                    'contact_personne_mobile_2'     => $contratData['contact_personne_mobile_2'] ?? null,
                    'branch_code'                   => $contratData['branch_code'] ?? null,
                    'partner_uuid'                  => $partnerUuid,
                    'conseiller_uuid'               => $userUuid,
                    'is_paid'                       => $contratData['is_paid'] ?? null,
                    'observation'                   => $contratData['observation'] ?? null,
                    'bulletin_num'                  => $contratData['bulletin_num'] ?? null,
                    'formule'                       => $contratData['formule'] ?? null,
                    'source_data'                   => $contratData['source_data'] ?? null,
                    'integration_key'               => $keyIntegration,
                    'created_by'                    => $draft?->created_by ?? $createdBy,
                    'date_transmission'             => $dateTransmission ?? null,
                    'transmis_par'                  => $transmisPar ?? null,
                    'acceptance_date'             => $dateAcceptee ?? null,
                    'accepted_by'                  => $accepted_by ?? null,
                ];

                if ($draft) {
                    $contratPayload['updated_by'] = $createdBy;
                    $contratStore = $this->contratService->update($draft, $contratPayload);
                } else {
                    $contratStore = $this->contratService->create($contratPayload);
                }

                if (!$contratStore) {
                    throw new \Exception("Échec de la création du contrat.");
                }

                return [
                    'contratData'     => $contratStore,
                    // 'key_integration' => $keyIntegration,
                    // 'contrat_uuid'    => $contratUuid,
                    'documentStore'   => $documentStore,
                    'documentWarning' => $documentWarning,
                    'adherentEmail'   => $adherentData['email'] ?? null,
                ];
            });

            

            return response()->json([
                'success'         => true,
                'message'         => 'Souscription créée avec succès',
                'code'            => 200,
                'contratData'     => $result['contratData'],
                'documentStore'   => $result['documentStore'],
                'documentWarning' => $result['documentWarning'],
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Brouillon introuvable.',
                'code' => 404,
            ], 404);
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 403,
            ], 403);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $th) {
            Log::error('Erreur lors de la souscription.', [
                'exception' => $th,
                'user_uuid' => $userUuid,
                'draft_uuid' => $draftUuid,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la création de la souscription.',
                'code'    => 500,
            ], 500);
        }
    }
}

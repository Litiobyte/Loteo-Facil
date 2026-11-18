<?php

namespace Tests\Feature;

use App\Domain\Imports\Services\ImportFileDownloads;
use App\Domain\Owners\Services\CommunitySettingsService;
use App\Filament\Admin\Pages\Imports\ImportHistory;
use App\Filament\Admin\Pages\Imports\ImportLots;
use App\Filament\Admin\Pages\Imports\ImportOwners;
use App\Filament\Admin\Pages\Imports\ImportShares;
use App\Filament\Resources\Propietarios\PropietarioResource;
use App\Models\Comuna;
use App\Models\DataImport;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\Propietario;
use App\Models\Region;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdministrativeImportUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
    }

    public function test_four_step_wizard_previews_then_requires_confirmation_and_executes(): void
    {
        $stage = Etapa::query()->create(['numero' => 80, 'nombre' => 'Etapa importación']);
        $page = Livewire::test(ImportLots::class)
            ->assertSee('Seleccionar archivo')
            ->assertSee('Mapear columnas')
            ->assertSee('Validar y previsualizar')
            ->assertSee('Ejecutar importación')
            ->assertSee('Paso 1 de 4')
            ->assertSee('data-step-state="active"', false);
        $this->assertStepperState($page->html(), 1);

        $page->set('etapaId', $stage->id)
            ->set('file', UploadedFile::fake()->createWithContent('lotes.csv', "codigo,metros_cuadrados,notas\nUI-001,5000,Prueba\n"))
            ->call('uploadFile')->assertHasNoErrors()
            ->assertSet('step', 2)->assertSee('UI-001')
            ->assertSee('Paso 2 de 4')
            ->assertSee('data-step-state="completed"', false);
        $this->assertStepperState($page->html(), 2);

        $page->call('preview')->assertHasNoErrors()
            ->assertSet('step', 3)->assertSee('todavía sin cambios')
            ->assertSee('Paso 3 de 4');
        $this->assertStepperState($page->html(), 3);
        $this->assertPreviewColumns($page->html(), [
            'codigo' => ['Código', 'UI-001'],
            'metros_cuadrados' => ['Superficie m² (solo creación)', '5000'],
            'notas' => ['Notas', 'Prueba'],
        ]);
        $this->assertDatabaseMissing('lotes', ['codigo' => 'UI-001']);
        $page->call('review')->assertSet('step', 4)
            ->assertSee('Paso 4 de 4')->assertSee('Confirmación pendiente')
            ->assertDontSee('Datos / cambios previstos');
        $this->assertPreviewColumns($page->html(), [
            'codigo' => ['Código', 'UI-001'],
            'metros_cuadrados' => ['Superficie m² (solo creación)', '5000'],
            'notas' => ['Notas', 'Prueba'],
        ]);
        $this->assertExecutionIsReadOnly($page->html());
        $this->assertDatabaseMissing('lotes', ['codigo' => 'UI-001']);
        $this->assertStepperState($page->html(), 4);

        $page->call('execute')->assertHasErrors(['confirmed']);
        $this->assertDatabaseMissing('lotes', ['codigo' => 'UI-001']);
        $page->set('confirmed', true)->call('execute')->assertHasNoErrors()
            ->assertSee('Completada')->assertSee('Resumen final')
            ->assertSee('data-step-state="completed"', false)
            ->assertDontSee('id="import-confirmed"', false);
        $this->assertPreviewColumns($page->html(), [
            'codigo' => ['Código', 'UI-001'],
            'metros_cuadrados' => ['Superficie m² (solo creación)', '5000'],
        ]);
        $this->assertExecutionIsReadOnly($page->html());
        $this->assertStepperState($page->html(), 4, finished: true);
        $this->assertDatabaseHas('lotes', ['codigo' => 'UI-001', 'metros_cuadrados' => 5000, 'etapa_id' => $stage->id]);
        $page->call('execute')->assertHasNoErrors();
        $this->assertSame(1, Lote::query()->where('codigo', 'UI-001')->count());
        $page->call('downloadReport')->assertFileDownloaded('importacion-'.DataImport::query()->sole()->id.'.csv');
    }

    public function test_uploaded_file_has_success_styling_only_while_a_file_is_loaded(): void
    {
        foreach ([ImportOwners::class, ImportLots::class, ImportShares::class] as $pageClass) {
            $page = Livewire::test($pageClass);
            $this->assertUploadLoadedClass($page->html(), false);

            $page->set('file', UploadedFile::fake()->createWithContent('importacion.csv', "rut,nombre\n12345678-5,Prueba\n"))
                ->assertHasNoErrors()
                ->assertSet('step', 1);
            $this->assertUploadLoadedClass($page->html(), true);
            $page->assertSee('.lf-import .lf-import-upload-loaded .fi-input-wrp-content-ctn {', false)
                ->assertSee('color: var(--color-success-700); font-weight: 700;', false)
                ->assertSee(".fi-input-wrp-content-ctn::before { content: '✓' / '';", false)
                ->assertSee('.fi-input-wrp-content-ctn > input[type="file"] { min-width: 0; color: inherit; font-weight: inherit; }', false)
                ->assertSee('.dark .lf-import .lf-import-upload-loaded .fi-input-wrp-content-ctn { color: var(--color-success-400); }', false);

            $page->set('file', null)->assertSet('step', 1);
            $this->assertUploadLoadedClass($page->html(), false);
        }
    }

    private function assertUploadLoadedClass(string $html, bool $loaded): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $uploads = $xpath->query('//div[@data-import-upload]');
        $this->assertCount(1, $uploads);
        $classes = explode(' ', $uploads->item(0)->getAttribute('class'));
        $this->assertContains('lf-import-upload', $classes);
        $this->assertSame($loaded, in_array('lf-import-upload-loaded', $classes, true));
    }

    private function assertStepperState(string $html, int $step, bool $finished = false): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $steps = $xpath->query('//ol[@class="lf-import-stepper-steps"]/li');
        $this->assertCount(4, $steps);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Pasos de importación"]//*[@aria-current="step"]')->length);

        foreach ($steps as $index => $item) {
            $number = $index + 1;
            $completed = $number < $step || $finished;
            $state = $completed ? 'completed' : ($number === $step ? 'active' : 'pending');
            $this->assertSame((string) $number, $item->getAttribute('data-step'));
            $this->assertSame($state, $item->getAttribute('data-step-state'));
            $this->assertSame($number === $step ? 'step' : '', $item->getAttribute('aria-current'));

            $circle = $xpath->query('./span[@class="lf-import-stepper-circle"]', $item)->item(0);
            $this->assertNotNull($circle);
            $this->assertSame($completed ? 1 : 0, $xpath->query('.//*[local-name()="svg"]', $circle)->length);
            if (! $completed) {
                $this->assertSame((string) $number, trim($circle->textContent));
            }

            $this->assertSame(1, $xpath->query('.//span[@class="lf-import-stepper-label"]', $item)->length);
            $this->assertSame(1, $xpath->query('.//span[@class="lf-import-stepper-description"]', $item)->length);
        }

        $percentage = (string) ($finished ? 100 : (int) round((($step - 1) / 3) * 100));
        $progress = $xpath->query('//div[@class="lf-import-stepper-progress"]')->item(0);
        $this->assertNotNull($progress);
        $this->assertSame('progressbar', $progress->getAttribute('role'));
        $this->assertSame('0', $progress->getAttribute('aria-valuemin'));
        $this->assertSame('100', $progress->getAttribute('aria-valuemax'));
        $this->assertSame($percentage, $progress->getAttribute('aria-valuenow'));
        $this->assertSame("Paso {$step} de 4", $progress->getAttribute('aria-valuetext'));
        $this->assertSame('width: '.(($step - 1) * 25).'%;', $progress->getAttribute('style'));
        $this->assertStringContainsString("{$percentage}% del recorrido", $html);
        $this->assertStringContainsString('.lf-import .lf-import-stepper-steps {', $html);
        $this->assertStringContainsString('grid-template-columns: repeat(4, minmax(0, 1fr))', $html);
        $this->assertStringContainsString('top: calc(var(--stepper-circle-size) / 2); left: 12.5%', $html);
    }

    public function test_pages_are_separate_and_templates_and_account_catalog_are_downloadable(): void
    {
        $this->assertSame('Importaciones', ImportOwners::getNavigationGroup());
        $this->assertSame('Importaciones', ImportLots::getNavigationGroup());
        $this->assertSame('Importaciones', ImportHistory::getNavigationGroup());
        $this->get(ImportOwners::getUrl())->assertOk();
        $this->get(ImportLots::getUrl())->assertOk();
        $this->get(ImportHistory::getUrl())->assertOk();
        Livewire::test(ImportOwners::class)
            ->call('downloadTemplate', 'csv')->assertFileDownloaded('plantilla-owners.csv')
            ->call('downloadTemplate', 'xlsx')->assertFileDownloaded('plantilla-owners.xlsx')
            ->call('downloadAccounts')->assertFileDownloaded('cuentas-disponibles.csv');
        Livewire::test(ImportLots::class)
            ->call('downloadTemplate', 'csv')->assertFileDownloaded('plantilla-lots.csv')
            ->call('downloadTemplate', 'xlsx')->assertFileDownloaded('plantilla-lots.xlsx');
    }

    public function test_shared_design_keeps_type_specific_help_and_supported_file_formats(): void
    {
        Livewire::test(ImportOwners::class)
            ->assertSee('lf-import-panel', false)
            ->assertSee('Consideraciones de importación')
            ->assertSee('<details', false)
            ->assertSee('Plantillas y ayuda')
            ->assertSee('Cuentas disponibles (ID/correo)')
            ->assertSee('contraseña inicial RUT+apellido')
            ->assertSee('accept=".csv,.xlsx"', false)
            ->assertDontSee('CSV, XLSX o XLS')
            ->assertDontSee('Guardar corrección');

        Livewire::test(ImportLots::class)
            ->assertSee('No es una importación geográfica KMZ/KML.')
            ->assertSee('Seleccione una etapa')
            ->assertDontSee('Cuentas disponibles (ID/correo)');

        Livewire::test(ImportShares::class)
            ->assertSee('Importe primero los propietarios.')
            ->assertSee('No se sobrescriben aportes existentes.')
            ->assertDontSee('No importa acciones, aportes, saldos, asignaciones ni movimientos financieros.');
    }

    public function test_shared_upload_area_preserves_native_livewire_input_and_accessible_help(): void
    {
        foreach ([ImportOwners::class, ImportLots::class, ImportShares::class] as $pageClass) {
            $html = Livewire::test($pageClass)->html();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query('//div[@data-import-upload]')->length);
            $input = $xpath->query('//div[@data-import-upload]//input[@id="import-file"]')->item(0);
            $this->assertNotNull($input);
            $this->assertSame('file', $input->getAttribute('type'));
            $this->assertSame('file', $input->getAttribute('wire:model'));
            $this->assertSame('.csv,.xlsx', $input->getAttribute('accept'));
            $this->assertSame('import-file-help', $input->getAttribute('aria-describedby'));
            $this->assertTrue($input->hasAttribute('required'));
            $this->assertFalse($input->hasAttribute('hidden'));
            $this->assertSame(1, $xpath->query('//label[@for="import-file"]')->length);
            $this->assertSame(1, $xpath->query('//p[@id="import-file-help"]')->length);
            $this->assertSame(1, $xpath->query('//*[@data-import-upload-error][@role="alert"][@hidden]')->length);
            $this->assertStringContainsString('Haga clic o arrastre un archivo aquí', $html);
            $this->assertMatchesRegularExpression('/\.lf-import \.lf-import-upload \{[^}]*cursor: pointer;/s', $html);
            $this->assertStringContainsString('.lf-import .lf-import-upload.lf-import-upload-dragover', $html);
            $this->assertStringContainsString('.lf-import .lf-import-upload:focus-within', $html);
        }

        $view = file_get_contents(resource_path('views/filament/admin/pages/imports/wizard.blade.php'));
        $this->assertStringContainsString('@script', $view);
        $this->assertStringContainsString('const importRoot = $wire.$el;', $view);
        $this->assertStringContainsString("['click', 'dragover', 'dragleave', 'drop']", $view);
        $this->assertStringContainsString('if (event.target !== input) input.click();', $view);
        $this->assertStringContainsString('const transfer = new DataTransfer();', $view);
        $this->assertStringContainsString('input.files = transfer.files;', $view);
        $this->assertStringContainsString("input.dispatchEvent(new Event('change', { bubbles: true }));", $view);
        $this->assertStringContainsString('Use el selector de archivos.', $view);
    }

    public function test_mapping_design_reflects_assignments_including_column_zero_without_writing_records(): void
    {
        Livewire::test(ImportOwners::class)
            ->set('file', UploadedFile::fake()->createWithContent('mapeo.csv', "rut,nombre,apellido,email\n11.222.333-9,Ana,Perez,ana@perez.cl\n"))
            ->call('uploadFile')->assertHasNoErrors()
            ->assertSee('Archivo cargado')
            ->assertSee('1 filas detectadas · 4 columnas disponibles')
            ->assertSee('4 de 12 campos relacionados.')
            ->assertSee('Columna origen para RUT')
            ->assertSee('Relacionada')
            ->assertSee('Omitida')
            ->set('mapping.rut', '')
            ->assertSee('3 de 12 campos relacionados.')
            ->set('mapping.rut', 0)
            ->assertSee('4 de 12 campos relacionados.')
            ->assertSee('Solo lectura · Desplácese horizontalmente');

        $this->assertDatabaseCount('propietarios', 0);
    }

    public function test_all_import_wizards_use_the_full_available_width_without_intrinsic_grid_expansion(): void
    {
        foreach ([ImportOwners::class, ImportLots::class, ImportShares::class] as $pageClass) {
            $html = Livewire::test($pageClass)->html();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($document);
            $wrapper = $xpath->query('//div[@data-import-step]')->item(0);
            $this->assertNotNull($wrapper);
            $this->assertStringContainsString('min-w-0', $wrapper->getAttribute('class'));
            $this->assertDoesNotMatchRegularExpression('/max-width\s*:\s*\d+(?:rem|px)/', $wrapper->getAttribute('style'));
            $this->assertMatchesRegularExpression('/\.lf-import\s*\{[^}]*width: 100%;[^}]*min-width: 0;[^}]*max-width: none;[^}]*grid-template-columns: minmax\(0, 1fr\);/s', $html);
            $this->assertStringContainsString('.fi-section-content) { min-width: 0; }', $html);
        }
    }

    public function test_wide_import_tables_keep_all_columns_inside_named_keyboard_accessible_scroll_regions(): void
    {
        $stage = Etapa::query()->create(['numero' => 87, 'nombre' => 'Archivo ancho']);
        $headers = ['codigo', 'metros_cuadrados', 'notas'];
        $values = ['ANCHO-001', '5000', 'Notas de prueba'];
        for ($column = 4; $column <= 64; $column++) {
            $headers[] = "extra_{$column}";
            $values[] = "valor_{$column}";
        }
        $csv = implode(',', $headers)."\n".implode(',', $values)."\n";
        $page = Livewire::test(ImportLots::class)
            ->set('etapaId', $stage->id)
            ->set('file', UploadedFile::fake()->createWithContent('ancho.csv', $csv))
            ->call('uploadFile')->assertHasNoErrors()->assertSet('step', 2)
            ->assertSee('extra_64')->assertSee('valor_64');
        $this->assertImportScrollRegion($page->html(), 65);
        $this->assertStringContainsString('.lf-import .lf-import-sample-table { width: max-content; min-width: 100%; }', $page->html());

        $page->call('preview')->assertHasNoErrors()->assertSet('step', 3);
        $this->assertImportScrollRegion($page->html(), 6);
        $this->assertPreviewColumns($page->html(), [
            'codigo' => ['Código', 'ANCHO-001'],
            'metros_cuadrados' => ['Superficie m² (solo creación)', '5000'],
            'notas' => ['Notas', 'Notas de prueba'],
        ]);

        $page->call('review')->assertSet('step', 4)->assertSee('ANCHO-001');
        $this->assertImportScrollRegion($page->html(), 6);
        $history = Livewire::test(ImportHistory::class)
            ->call('showDetails', DataImport::query()->sole()->id)
            ->assertSee('ANCHO-001');
        $this->assertImportScrollRegion($history->html(), 4);
        $this->assertStringContainsString('lf-import-history', $history->html());
        $this->assertDatabaseMissing('lotes', ['codigo' => 'ANCHO-001']);
    }

    private function assertImportScrollRegion(string $html, int $expectedColumns): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $regions = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " lf-import-table-scroll ")]');
        $this->assertCount(1, $regions);
        $region = $regions->item(0);
        $this->assertSame('region', $region->getAttribute('role'));
        $this->assertSame('0', $region->getAttribute('tabindex'));
        $this->assertNotSame('', $region->getAttribute('aria-label'));
        $this->assertCount($expectedColumns, $xpath->query('./table/thead/tr/th', $region));
        $this->assertCount($expectedColumns, $xpath->query('./table/tbody/tr[1]/*', $region));
        $this->assertStringContainsString('.lf-import-table-scroll { min-width: 0; max-width: 100%; overflow-x: auto; }', $html);
        $this->assertStringContainsString('.lf-import-table-scroll:focus-visible', $html);
    }

    public function test_history_shows_preview_and_row_details_without_deletion_actions(): void
    {
        $stage = Etapa::query()->create(['numero' => 81, 'nombre' => 'Historia']);
        Livewire::test(ImportLots::class)
            ->set('etapaId', $stage->id)
            ->set('file', UploadedFile::fake()->createWithContent('historial.csv', "codigo,metros_cuadrados\nHIST-001,5000\n"))
            ->call('uploadFile')->call('preview')->assertHasNoErrors();
        $import = DataImport::query()->sole();
        $this->get(ImportLots::getUrl(['import' => $import->id]))->assertOk()->assertSee('HIST-001');
        Livewire::test(ImportHistory::class)
            ->assertCanSeeTableRecords([$import])
            ->call('showDetails', $import->id)->assertSee('HIST-001')
            ->assertSee('Datos / cambios previstos')
            ->assertDontSee('data-import-field=', false)
            ->callTableAction('report', $import)->assertFileDownloaded("importacion-{$import->id}.csv")
            ->callTableAction('original', $import)->assertFileDownloaded('historial.csv');
        $this->assertDatabaseMissing('lotes', ['codigo' => 'HIST-001']);
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('admin');
        $this->actingAs($otherAdmin)->get(ImportLots::getUrl(['import' => $import->id]))->assertForbidden();
        $this->actingAs($this->admin)->get(ImportOwners::getUrl(['import' => $import->id]))->assertForbidden();
    }

    public function test_preview_row_pagination_remains_available_in_the_redesigned_panel(): void
    {
        $stage = Etapa::query()->create(['numero' => 84, 'nombre' => 'Paginación']);
        $csv = "codigo,metros_cuadrados\n";
        for ($number = 1; $number <= 251; $number++) {
            $csv .= sprintf("PAG-%03d,5000\n", $number);
        }

        $page = Livewire::test(ImportLots::class)
            ->set('etapaId', $stage->id)
            ->set('file', UploadedFile::fake()->createWithContent('paginacion.csv', $csv))
            ->call('uploadFile')->call('preview')->assertHasNoErrors()
            ->assertSee('PAG-001')->assertSee('PAG-010')->assertDontSee('PAG-011')
            ->assertSee('Mostrando 1–10 de 251 registros')
            ->assertSee('wire:click="nextPage(\'page\')"', false)
            ->assertSee('wire:click="gotoPage(2, \'page\')"', false)
            ->assertSee('<span class="lf-import-preview-page-gap" aria-hidden="true">...</span>', false);
        $this->assertPreviewPaginationState($page->html(), 1, true, false);

        $page->call('nextPage', 'page')
            ->assertSee('PAG-011')->assertSee('PAG-020')->assertDontSee('PAG-021')->assertDontSee('PAG-001')
            ->assertSee('Mostrando 11–20 de 251 registros')
            ->assertSee('wire:click="previousPage(\'page\')"', false)
            ->assertSee('wire:click="gotoPage(26, \'page\')"', false);
        $this->assertPreviewPaginationState($page->html(), 2, false, false);
        $this->assertPreviewColumns($page->html(), [
            'codigo' => ['Código', 'PAG-011'],
            'metros_cuadrados' => ['Superficie m² (solo creación)', '5000'],
        ]);

        $page->call('gotoPage', 26, 'page')
            ->assertSee('PAG-251')->assertDontSee('PAG-250')
            ->assertSee('Mostrando 251–251 de 251 registros');
        $this->assertPreviewPaginationState($page->html(), 26, false, true);

        $page->call('previousPage', 'page')
            ->assertSee('PAG-241')->assertSee('PAG-250')->assertDontSee('PAG-251')
            ->assertSee('Mostrando 241–250 de 251 registros')
            ->call('gotoPage', 1, 'page')
            ->assertSee('PAG-001')->assertDontSee('PAG-011')
            ->assertSet('step', 3);
        $this->assertPreviewPaginationState($page->html(), 1, true, false);

        $this->assertDatabaseCount('lotes', 0);

        $page->call('gotoPage', 20, 'page')
            ->set('statusFilter', 'create')->assertSet('paginators.page', 1)
            ->assertSee('Mostrando 1–10 de 251 registros')
            ->call('nextPage', 'page')
            ->set('search', ' pag-0 ')->assertSet('paginators.page', 1)
            ->assertSee('Mostrando 1–10 de 99 registros')
            ->assertSee('99 de 251 registros')
            ->call('gotoPage', 10, 'page')
            ->assertSee('PAG-091')->assertSee('PAG-099')->assertDontSee('PAG-100')
            ->assertSee('Mostrando 91–99 de 99 registros')
            ->set('search', 'PAG-251')->assertSet('paginators.page', 1)
            ->assertSee('PAG-251')->assertDontSee('PAG-001')
            ->assertSee('Mostrando 1–1 de 1 registros');

        $page->call('review')->assertSet('step', 4)
            ->assertSee('Mostrando 1–10 de 251 registros')
            ->assertDontSee('id="import-status-filter"', false)
            ->assertDontSee('id="import-preview-search"', false);
        $this->assertPreviewPaginationState($page->html(), 1, true, false);
        $page->call('nextPage', 'page')
            ->assertSee('PAG-011')->assertDontSee('PAG-001')->assertDontSee('PAG-021')
            ->assertSee('Mostrando 11–20 de 251 registros');
        $this->assertExecutionIsReadOnly($page->html());
        $this->assertPreviewPaginationState($page->html(), 2, false, false);
        $this->assertDatabaseCount('lotes', 0);
        $page->set('confirmed', true)->call('execute')->assertHasNoErrors()
            ->assertSee('Resumen final');
        $this->assertPreviewPaginationState($page->html(), 2, false, false);
        $page->call('gotoPage', 26, 'page')
            ->assertSee('PAG-251')->assertDontSee('PAG-250')
            ->assertSee('Mostrando 251–251 de 251 registros');
        $this->assertPreviewPaginationState($page->html(), 26, false, true);
        $this->assertPreviewColumns($page->html(), [
            'codigo' => ['Código', 'PAG-251'],
            'metros_cuadrados' => ['Superficie m² (solo creación)', '5000'],
        ]);
        $page->call('previousPage', 'page')->assertSee('PAG-241')->assertSee('PAG-250')->assertDontSee('PAG-251');
        $this->assertPreviewPaginationState($page->html(), 25, false, false);
        $this->assertExecutionIsReadOnly($page->html());
        $this->assertDatabaseCount('lotes', 251);
        $this->assertSame(251, DataImport::query()->sole()->rows()->where('result', 'create')->count());
    }

    public function test_preview_filters_operations_and_keeps_correction_actions_available(): void
    {
        $stage = Etapa::query()->create(['numero' => 87, 'nombre' => 'Filtros']);
        Lote::factory()->create(['codigo' => 'FILTRO-UPDATE', 'etapa_id' => $stage->id, 'metros_cuadrados' => 5000, 'notas' => 'Antes']);
        Lote::factory()->create(['codigo' => 'FILTRO-SKIP', 'etapa_id' => $stage->id, 'metros_cuadrados' => 5000, 'notas' => 'Igual']);
        $page = Livewire::test(ImportLots::class)
            ->set('etapaId', $stage->id)
            ->set('file', UploadedFile::fake()->createWithContent('filtros.csv', "codigo,metros_cuadrados,notas\nFILTRO-CREATE,5000,Nuevo\nFILTRO-UPDATE,5000,Después\nFILTRO-SKIP,5000,Igual\nFILTRO-ERROR,no-es-numero,Inválido\n"))
            ->call('uploadFile')
            ->assertDontSee('id="import-preview-search"', false)
            ->set('duplicateMode', 'update')
            ->call('preview')->assertHasNoErrors()
            ->assertSee('id="import-status-filter"', false)
            ->assertSee('wire:model.live="statusFilter"', false)
            ->assertSee('wire:model.live.debounce.300ms="search"', false);

        foreach (['create', 'update', 'skip', 'error'] as $status) {
            $page->set('statusFilter', $status)->assertHasNoErrors()
                ->assertSee('FILTRO-'.strtoupper($status))
                ->assertSee('Mostrando 1–1 de 1 registros')
                ->assertSee('1 de 4 registros');
            foreach (array_diff(['create', 'update', 'skip', 'error'], [$status]) as $otherStatus) {
                $page->assertDontSee('FILTRO-'.strtoupper($otherStatus));
            }
        }

        $row = DataImport::query()->sole()->rows()->where('action', 'error')->sole();
        $page->assertSee('wire:click="editRow('.$row->id.')"', false)
            ->call('editRow', $row->id)->assertSet('editingRowId', $row->id)
            ->set('search', 'sin coincidencias')->assertSet('editingRowId', null)
            ->assertSee('No hay registros que coincidan con los filtros.')
            ->assertSee('Mostrando 0–0 de 0 registros')
            ->set('search', '')->set('statusFilter', '')
            ->assertSee('Mostrando 1–4 de 4 registros')
            ->set('statusFilter', 'invalid')->assertSet('statusFilter', '')
            ->assertSee('Mostrando 1–4 de 4 registros');
        $this->assertDatabaseCount('lotes', 2);
    }

    public function test_preview_searches_decoded_original_normalized_and_corrected_values_literally(): void
    {
        $stage = Etapa::query()->create(['numero' => 88, 'nombre' => 'Búsqueda']);
        $page = Livewire::test(ImportLots::class)
            ->set('etapaId', $stage->id)
            ->set('file', UploadedFile::fake()->createWithContent('busqueda.csv', "codigo,metros_cuadrados,notas\nBUSCA-UNO,5000,Árbol 100%_único\nBUSCA-DOS,no-es-numero,Otro\n"))
            ->call('uploadFile')->call('preview')->assertHasNoErrors()
            ->set('search', 'áRBOL 100%_ÚNICO')
            ->assertSee('BUSCA-UNO')->assertDontSee('BUSCA-DOS')
            ->assertSee('Mostrando 1–1 de 1 registros')
            ->set('search', 'no-es-numero')
            ->assertSee('BUSCA-DOS')->assertDontSee('BUSCA-UNO');

        $row = DataImport::query()->sole()->rows()->where('action', 'create')->sole();
        $row->update([
            'data' => array_replace($row->data, ['notas' => 'Normalización especial']),
            'overrides' => ['notas' => 'Corrección especial'],
        ]);
        DataImport::query()->sole()->rows()->where('action', 'error')->sole()->update(['errors' => ['Mensaje exclusivo de error']]);
        $page->set('search', 'corrección especial')
            ->assertSee('BUSCA-UNO')->assertDontSee('BUSCA-DOS')
            ->set('search', 'normalización especial')
            ->assertSee('BUSCA-UNO')->assertDontSee('BUSCA-DOS')
            ->set('search', 'Mensaje exclusivo de error')->assertSee('Mostrando 0–0 de 0 registros')
            ->set('search', 'codigo')->assertSee('Mostrando 0–0 de 0 registros')
            ->set('search', '<script>')->assertSee('Mostrando 0–0 de 0 registros');
        $this->assertDatabaseCount('lotes', 0);
    }

    private function assertExecutionIsReadOnly(string $html): void
    {
        $this->assertStringContainsString('id="import-execution-title"', $html);
        $this->assertStringContainsString('class="lf-import-preview-card"', $html);
        $this->assertStringNotContainsString('wire:click="editRow(', $html);
        $this->assertStringNotContainsString('Corregir datos', $html);
        $this->assertStringNotContainsString('Guardar corrección', $html);
        $this->assertStringNotContainsString('wire:click="remap"', $html);
        $this->assertStringNotContainsString('wire:click="review"', $html);
    }

    private function assertPreviewPaginationState(string $html, int $currentPage, bool $previousDisabled, bool $nextDisabled): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//table[@class="lf-import-preview-table"]/tbody/tr');
        $this->assertCount($nextDisabled && $currentPage > 1 ? 1 : 10, $rows);
        $navigation = $xpath->query('//nav[@aria-label="Paginación de registros"]');
        $this->assertCount(1, $navigation);
        $current = $xpath->query('.//button[@aria-current="page"]', $navigation->item(0));
        $this->assertCount(1, $current);
        $this->assertSame((string) $currentPage, trim($current->item(0)->textContent));

        foreach (['Página anterior' => $previousDisabled, 'Página siguiente' => $nextDisabled] as $label => $disabled) {
            $button = $xpath->query(".//button[@aria-label='{$label}']", $navigation->item(0))->item(0);
            $this->assertNotNull($button);
            $this->assertSame($disabled, $button->hasAttribute('disabled'));
            $this->assertSame($disabled ? 'true' : '', $button->getAttribute('aria-disabled'));
            $this->assertSame(! $disabled, $button->hasAttribute('wire:click'));
        }

        $footer = $xpath->query('//div[@class="lf-import-preview-pagination"]')->item(0);
        $this->assertNotNull($footer);
        $this->assertCount(1, $xpath->query('./p[@role="status"]', $footer));
        $this->assertCount(1, $xpath->query('./nav', $footer));
        $this->assertStringContainsString('.lf-import .lf-import-preview-pages > button:focus-visible', $html);
        $this->assertStringContainsString('.lf-import .lf-import-preview-pagination { display: flex; flex-wrap: wrap;', $html);
    }

    /** @param array<string, array{0: string, 1: string}> $expected */
    private function assertPreviewColumns(string $html, array $expected): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $table = $xpath->query('//table[@class="lf-import-preview-table"]')->item(0);
        $this->assertNotNull($table);
        $headers = $xpath->query('./thead/tr/th', $table);

        foreach ($expected as $field => [$label, $value]) {
            $header = $xpath->query("./thead/tr/th[@data-import-field='{$field}']", $table);
            $this->assertCount(1, $header);
            $this->assertSame($label, trim($header->item(0)->textContent));
            $cell = $xpath->query("./tbody/tr[1]/td[@data-import-field='{$field}']", $table);
            $this->assertCount(1, $cell);
            $this->assertSame($value, preg_replace('/\s+/u', ' ', trim($cell->item(0)->textContent)));
        }

        foreach ($xpath->query('./tbody/tr', $table) as $row) {
            $this->assertCount($headers->length, $xpath->query('./th|./td', $row));
        }
    }

    public function test_preview_pagination_disables_both_directions_for_a_single_page(): void
    {
        $stage = Etapa::query()->create(['numero' => 86, 'nombre' => 'Página única']);
        $csv = "codigo,metros_cuadrados\n";
        for ($number = 1; $number <= 10; $number++) {
            $csv .= "UNICO-{$number},5000\n";
        }
        $page = Livewire::test(ImportLots::class)
            ->set('etapaId', $stage->id)
            ->set('file', UploadedFile::fake()->createWithContent('pagina-completa.csv', $csv))
            ->call('uploadFile')->call('preview')->assertHasNoErrors()
            ->assertSee('Mostrando 1–10 de 10 registros');

        $this->assertPreviewPaginationState($page->html(), 1, true, true);
        $this->assertDatabaseCount('lotes', 0);
    }

    public function test_validation_design_uses_real_totals_and_allows_valid_rows_to_continue_despite_errors(): void
    {
        $stage = Etapa::query()->create(['numero' => 85, 'nombre' => 'Validación']);
        Lote::factory()->create(['codigo' => 'EXISTENTE', 'etapa_id' => $stage->id, 'metros_cuadrados' => 5000, 'estado' => 'disponible']);

        $page = Livewire::test(ImportLots::class)
            ->set('etapaId', $stage->id)
            ->set('file', UploadedFile::fake()->createWithContent('validacion-real.csv', "codigo,metros_cuadrados\nNUEVO,5000\nEXISTENTE,5000\nINVALIDO,no-es-numero\n"))
            ->call('uploadFile')->call('preview')->assertHasNoErrors()
            ->assertSee('Validación de registros')
            ->assertSee('validacion-real.csv')
            ->assertSee('Listos para importar')
            ->assertSee('Omitidos / advertencias')
            ->assertSee('1 creaciones')->assertSee('0 actualizaciones')
            ->assertSee('1 omitidos')->assertSee('1 rechazados')
            ->assertSee('Los registros omitidos no se importarán.')
            ->assertSee('Errores de la fila 4')
            ->assertSee('wire:click="remap"', false)
            ->assertSee('wire:click="review"', false)
            ->assertSee('Buscar registros');

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $xpath = new \DOMXPath($document);
        foreach (['total' => '3', 'valid' => '1', 'error' => '1', 'warning' => '1'] as $state => $count) {
            $this->assertSame($count, trim($xpath->query("//div[@data-validation-state='{$state}']/dd")->item(0)->textContent));
        }
        foreach (['total' => 'primary', 'valid' => 'success', 'error' => 'danger', 'warning' => 'warning'] as $state => $color) {
            $this->assertStringContainsString('.lf-import .lf-import-preview-stat[data-validation-state="'.$state.'"] { --import-stat-color: var(--color-'.$color.'-500);', $page->html());
            $this->assertStringContainsString('.dark .lf-import .lf-import-preview-stat[data-validation-state="'.$state.'"] { color: var(--color-'.$color.'-300); }', $page->html());
        }
        $this->assertStringContainsString('background: color-mix(in srgb, var(--import-stat-color) 20%, var(--import-surface))', $page->html());
        $this->assertStringContainsString('background: color-mix(in srgb, var(--import-stat-color) 25%, var(--import-surface))', $page->html());
        $this->assertCount(0, $xpath->query('//input[@id="import-preview-search"]/ancestor::form|//select[@id="import-status-filter"]/ancestor::form'));
        $this->assertSame(1, $xpath->query('//footer//button[@*[name()="wire:click"]="review" and not(@disabled)]')->length);
        $this->assertStringContainsString('.lf-import .lf-import-preview-stats { display: grid;', $page->html());
        $this->assertStringContainsString('.lf-import-table-scroll { min-width: 0; max-width: 100%; overflow-x: auto; }', $page->html());
        $this->assertDatabaseCount('lotes', 1);
        $import = DataImport::query()->sole();
        $page->call('downloadReport', false)->assertFileDownloaded("importacion-{$import->id}.csv")
            ->call('downloadReport', true)->assertFileDownloaded("importacion-{$import->id}-errores.csv")
            ->call('review')->assertSet('step', 4)
            ->assertSee('Confirmación pendiente')
            ->assertDontSee('id="import-validation-title"', false);
        $this->assertExecutionIsReadOnly($page->html());
        $this->assertDatabaseCount('lotes', 1);
        $page->set('confirmed', true)->call('execute')->assertHasNoErrors()
            ->assertSee('Importación completada con errores')
            ->assertSee('Omitir / advertencia')
            ->assertSee('Rechazar');
        $this->assertExecutionIsReadOnly($page->html());

        $this->assertDatabaseHas('lotes', ['codigo' => 'NUEVO']);
        $this->assertDatabaseMissing('lotes', ['codigo' => 'INVALIDO']);
        Livewire::test(ImportHistory::class)->call('showDetails', $import->id)
            ->assertSee('NUEVO')->assertDontSee('id="import-validation-title"', false);
    }

    public function test_share_preview_keeps_specific_guidance_and_real_validation_details(): void
    {
        app(CommunitySettingsService::class)->update([
            'mode' => 'shares', 'square_meters_per_share' => '800',
            'share_precision' => 4, 'authorized_shares' => null,
        ], 'Configurar importación', $this->admin);
        Propietario::factory()->create(['rut' => '12345678-5']);
        $page = Livewire::test(ImportShares::class)
            ->set('file', UploadedFile::fake()->createWithContent('saldos.csv', "rut,quantity,amount\n12345678-5,10,1000\n"))
            ->call('uploadFile')->call('preview')->assertHasNoErrors()
            ->assertSet('step', 3)
            ->assertSee('Validación de registros')
            ->assertSee('No se sobrescriben aportes existentes.')
            ->assertSee('1 creaciones')
            ->assertDontSee('No importa acciones, aportes, saldos, asignaciones ni movimientos financieros.');
        $this->assertPreviewColumns($page->html(), [
            'rut' => ['RUT del propietario existente', '12345678-5'],
            'quantity' => ['Cantidad total de acciones', '10'],
            'amount' => ['Total aportado (vacío = desconocido)', '1000'],
        ]);
        $page->assertDontSee('data-import-field="notes"', false)
            ->call('remap')->assertSet('step', 2)
            ->assertDontSee('id="import-validation-title"', false)
            ->call('preview')->call('review')->assertSet('step', 4);
        $this->assertPreviewColumns($page->html(), [
            'rut' => ['RUT del propietario existente', '12345678-5'],
            'quantity' => ['Cantidad total de acciones', '10'],
            'amount' => ['Total aportado (vacío = desconocido)', '1000'],
        ]);
        $this->assertExecutionIsReadOnly($page->html());
        $this->assertDatabaseCount('share_holdings', 0);
        $page->set('confirmed', true)->call('execute')->assertHasNoErrors()->assertSee('Importación completada');
        $this->assertPreviewColumns($page->html(), [
            'rut' => ['RUT del propietario existente', '12345678-5'],
            'quantity' => ['Cantidad total de acciones', '10'],
            'amount' => ['Total aportado (vacío = desconocido)', '1000'],
        ]);
        $this->assertExecutionIsReadOnly($page->html());
        $this->assertDatabaseCount('share_holdings', 1);
    }

    public function test_ambiguous_owner_row_can_be_corrected_from_the_wizard(): void
    {
        Propietario::factory()->create(['rut' => '98765432-1', 'email' => 'b@example.com']);

        $page = Livewire::test(ImportOwners::class)
            ->set('file', UploadedFile::fake()->createWithContent('owners.csv', "RUT,Nombre,Apellido,Email\n12.345.678-5,Ana,Torres,b@example.com\n"))
            ->call('uploadFile')->assertHasNoErrors()
            ->call('preview')->assertHasNoErrors()
            ->assertSee('Corregir datos');

        $import = DataImport::query()->sole();
        $this->assertSame(1, $import->error_count);
        $row = $import->rows()->firstOrFail();

        $page->call('editRow', $row->id)
            ->assertSet('editingRowId', $row->id)
            ->assertSee('Corregir fila')
            ->assertDontSee('id="import-correction-title"', false);
        $this->assertInlineRowEditor($page->html(), $row->id, ['rut', 'nombre', 'apellido', 'email', 'telefono']);
        $page->set('editingData.email', 'descartado@example.com')
            ->call('cancelRowEdit')
            ->assertSet('editingRowId', null)
            ->assertSet('editingData', [])
            ->assertDontSee('Guardar y revalidar');
        $this->assertSame([], $row->fresh()->overrides ?? []);
        $this->assertSame(1, $import->fresh()->error_count);
        $page->call('editRow', $row->id)
            ->set('editingData.email', 'nuevo@example.com')
            ->call('saveRow')->assertHasNoErrors()
            ->assertSee('Validación completa — todavía sin cambios')
            ->assertSee('1 creaciones')->assertSee('0 rechazados')
            ->assertDontSee('Guardar y revalidar');

        $import = $import->refresh();
        $this->assertPreviewColumns($page->html(), [
            'rut' => ['RUT', '12345678-5'],
            'nombre' => ['Nombre', 'Ana'],
            'apellido' => ['Apellido', 'Torres'],
            'email' => ['Correo electrónico', 'nuevo@example.com'],
        ]);
        $page->assertDontSee('data-import-field="telefono"', false);
        $this->assertSame(0, $import->error_count);
        $this->assertSame(1, $import->created_count);
        $this->assertSame(['email' => 'nuevo@example.com'], $import->rows()->firstOrFail()->overrides);

        $page->call('review')->assertSet('step', 4);
        $this->assertPreviewColumns($page->html(), [
            'rut' => ['RUT', '12345678-5'],
            'email' => ['Correo electrónico', 'nuevo@example.com'],
        ]);
        $this->assertExecutionIsReadOnly($page->html());
        $this->assertDatabaseMissing('propietarios', ['rut' => '12345678-5']);
        $page->set('confirmed', true)->call('execute')->assertHasNoErrors();
        $this->assertPreviewColumns($page->html(), [
            'rut' => ['RUT', '12345678-5'],
            'email' => ['Correo electrónico', 'nuevo@example.com'],
        ]);
        $this->assertExecutionIsReadOnly($page->html());

        $this->assertDatabaseHas('propietarios', ['rut' => '12345678-5', 'email' => 'nuevo@example.com']);
        $account = User::query()->where('email', 'nuevo@example.com')->firstOrFail();
        $this->assertSame($account->id, Propietario::query()->where('rut', '12345678-5')->firstOrFail()->user_id);
    }

    private function assertInlineRowEditor(string $html, int $rowId, array $fields): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $row = $xpath->query('//table[contains(@class, "lf-import-preview-table")]/tbody/tr[@data-import-row="'.$rowId.'"]')->item(0);
        $this->assertNotNull($row);
        $this->assertSame('true', $row->getAttribute('data-editing'));
        $this->assertSame(1, $xpath->query('//tr[@data-editing="true"]')->length);
        $this->assertSame(0, $xpath->query('//td[@colspan]')->length);
        $this->assertSame(0, $xpath->query('//form[@*[name()="wire:submit"]="saveRow"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="import-correction-title"]')->length);
        foreach ($fields as $field) {
            $input = $xpath->query('./td[@data-import-field="'.$field.'"]//input', $row)->item(0);
            $this->assertNotNull($input, "Input {$field} must be in its own data cell.");
            $this->assertSame('editingData.'.$field, $input->getAttribute('wire:model'));
            $this->assertSame('saveRow', $input->getAttribute('wire:keydown.enter.prevent'));
            $this->assertSame('cancelRowEdit', $input->getAttribute('wire:keydown.escape.prevent'));
            $this->assertSame('import-row-errors-'.$rowId, $input->getAttribute('aria-describedby'));
            $this->assertSame(1, $xpath->query('//label[@for="'.$input->getAttribute('id').'"]')->length);
        }
        $this->assertSame(1, $xpath->query('./td[contains(@class, "lf-import-preview-conflicts")]//button[@*[name()="wire:click"]="saveRow"]', $row)->length);
        $this->assertSame(1, $xpath->query('./td[contains(@class, "lf-import-preview-conflicts")]//button[@*[name()="wire:click"]="cancelRowEdit"]', $row)->length);
        $this->assertSame(0, $xpath->query('//input[starts-with(@*[name()="wire:model"], "editingData.") and not(ancestor::tr[@data-import-row="'.$rowId.'"])]')->length);
    }

    public function test_inline_owner_selects_use_real_options_and_search_only_above_four(): void
    {
        $regions = collect(range(1, 4))->map(fn (int $number) => Region::query()->create(['nombre' => "Región prueba {$number}"]));
        foreach (range(1, 5) as $number) {
            Comuna::query()->create(['nombre' => "Comuna prueba {$number}", 'region_id' => $regions->first()->id]);
        }
        $page = $this->ownerSelectPreview();
        $row = DataImport::query()->sole()->rows()->firstOrFail();
        $page->call('editRow', $row->id)->assertHasNoErrors()
            ->assertSet('editingSelections.region_id', null)
            ->assertSet('editingSelections.comuna_id', null)
            ->assertSet('editingSelections.estado_civil', null)
            ->assertSee('Valor original sin selección válida: desconocida')
            ->assertSee('Valor original sin selección válida: invalido');
        $region = $page->instance()->inlineSelectForm->getComponent('region_id');
        $this->assertInstanceOf(Select::class, $region);
        $this->assertCount(4, $region->getOptions());
        $this->assertFalse($region->isSearchable());
        $comuna = $page->instance()->inlineSelectForm->getComponent('comuna_id');
        $this->assertInstanceOf(Select::class, $comuna);
        $this->assertCount(5, $comuna->getOptions());
        $this->assertTrue($comuna->isSearchable());
        $civil = $page->instance()->inlineSelectForm->getComponent('estado_civil');
        $this->assertSame(PropietarioResource::maritalStatusOptions(), $civil->getOptions());
        $this->assertTrue($civil->isSearchable());
        $this->assertInlineRowEditor($page->html(), $row->id, ['rut', 'nombre', 'apellido', 'email']);
        Region::query()->create(['nombre' => 'Quinta región']);
        $page->call('$refresh')->assertHasNoErrors();
        $this->assertTrue($page->instance()->inlineSelectForm->getComponent('region_id')->isSearchable());
        $this->assertCount(5, $page->instance()->inlineSelectForm->getComponent('region_id')->getOptions());
    }

    public function test_inline_owner_selects_filter_clear_cancel_and_save_explicit_ids(): void
    {
        $north = Region::query()->create(['nombre' => 'Norte']);
        $south = Region::query()->create(['nombre' => 'Sur']);
        $first = Comuna::query()->create(['nombre' => 'Central', 'region_id' => $north->id]);
        $second = Comuna::query()->create(['nombre' => 'Central', 'region_id' => $south->id]);
        $page = $this->ownerSelectPreview('Central');
        $import = DataImport::query()->sole();
        $row = $import->rows()->firstOrFail();
        $page->call('editRow', $row->id)->assertSet('editingSelections.comuna_id', null)
            ->assertSee('Comuna ambigua')
            ->set('editingSelections.region_id', (string) $north->id)
            ->assertSet('editingData.region_id', (string) $north->id)
            ->set('editingSelections.comuna_id', (string) $first->id);
        $this->assertSame([$first->id], array_keys($page->instance()->inlineSelectOptions('comuna_id')));
        $page->set('editingSelections.region_id', (string) $south->id)
            ->assertSet('editingSelections.comuna_id', null)
            ->assertSet('editingData.comuna_id', '');
        $this->assertSame([$second->id], array_keys($page->instance()->inlineSelectOptions('comuna_id')));
        $page->set('editingSelections.comuna_id', (string) $second->id)
            ->set('editingSelections.estado_civil', 'Casado')
            ->call('cancelRowEdit')
            ->assertSet('editingSelections', [])->assertSet('editingData', []);
        $this->assertSame([], $row->fresh()->overrides ?? []);
        $page->call('editRow', $row->id)
            ->assertSet('editingSelections.region_id', null)
            ->set('editingSelections.region_id', (string) $south->id)
            ->set('editingSelections.comuna_id', (string) $second->id)
            ->set('editingSelections.estado_civil', 'Casado')
            ->call('saveRow')->assertHasNoErrors()
            ->assertSet('editingRowId', null)->assertSee('0 rechazados');
        $this->assertSame(0, $import->fresh()->error_count);
        $this->assertSame((string) $south->id, $row->fresh()->overrides['region_id']);
        $this->assertSame((string) $second->id, $row->fresh()->overrides['comuna_id']);
        $this->assertSame('Casado', $row->fresh()->overrides['estado_civil']);
        $this->assertDatabaseCount('propietarios', 0);
    }

    public function test_inline_owner_location_names_resolve_without_silently_choosing_a_region(): void
    {
        $region = Region::query()->create(['nombre' => 'Valparaíso']);
        $comuna = Comuna::query()->create(['nombre' => 'Viña del Mar', 'region_id' => $region->id]);
        $page = $this->ownerSelectPreview('vina mar', '');
        $row = DataImport::query()->sole()->rows()->firstOrFail();
        $page->call('editRow', $row->id)->assertHasNoErrors()
            ->assertSet('editingSelections.region_id', null)
            ->assertSet('editingSelections.comuna_id', (string) $comuna->id)
            ->assertSet('editingData.region_id', '');
        $page->set('editingSelections.region_id', (string) $region->id)
            ->assertSet('editingSelections.comuna_id', (string) $comuna->id)
            ->set('editingSelections.estado_civil', 'Soltero')
            ->call('saveRow')->assertHasNoErrors();
        $this->assertSame(0, DataImport::query()->sole()->error_count);
    }

    public function test_inline_owner_ambiguous_region_and_invalid_values_remain_until_explicit_choice(): void
    {
        Region::query()->create(['nombre' => 'Norte Uno']);
        $region = Region::query()->create(['nombre' => 'Norte Dos']);
        $comuna = Comuna::query()->create(['nombre' => 'Localidad', 'region_id' => $region->id]);
        $page = $this->ownerSelectPreview('Localidad', 'Norte');
        $import = DataImport::query()->sole();
        $row = $import->rows()->firstOrFail();
        $page->call('editRow', $row->id)->assertHasNoErrors()
            ->assertSet('editingSelections.region_id', null)
            ->assertSet('editingSelections.comuna_id', (string) $comuna->id)
            ->assertSee('Valor original sin selección válida: Norte')
            ->set('editingData.nombre', 'Ana corregida')
            ->call('saveRow')->assertHasNoErrors()->assertSee('Región ambigua');
        $page->call('editRow', $row->id)
            ->assertSet('editingData.region_id', 'Norte')
            ->assertSet('editingData.estado_civil', 'invalido')
            ->set('editingSelections.region_id', (string) $region->id)
            ->assertSet('editingSelections.comuna_id', (string) $comuna->id)
            ->set('editingSelections.estado_civil', 'Union civil')
            ->call('saveRow')->assertHasNoErrors();
        $this->assertSame(0, $import->fresh()->error_count);
        $this->assertSame((string) $region->id, $row->fresh()->overrides['region_id']);
    }

    private function ownerSelectPreview(string $comuna = 'desconocida', string $region = 'desconocida'): Testable
    {
        return Livewire::test(ImportOwners::class)
            ->set('file', UploadedFile::fake()->createWithContent('selects.csv', "rut,nombre,apellido,email,region,comuna,estado_civil\n12.345.678-5,Ana,Torres,selects@example.cl,{$region},{$comuna},invalido\n"))
            ->call('uploadFile')->call('preview')->assertHasNoErrors();
    }

    public function test_inline_lot_correction_revalidates_invalid_values_and_isolates_row_drafts(): void
    {
        $stage = Etapa::query()->create(['numero' => 89, 'nombre' => 'Corrección en línea']);
        $page = Livewire::test(ImportLots::class)
            ->set('etapaId', $stage->id)
            ->set('file', UploadedFile::fake()->createWithContent('errores.csv', "codigo,metros_cuadrados\nPRIMERO,no-valido\nSEGUNDO,no-valido\n"))
            ->call('uploadFile')->call('preview')->assertHasNoErrors();
        $import = DataImport::query()->sole();
        [$first, $second] = $import->rows()->orderBy('number')->get()->all();

        $page->call('editRow', $first->id)->set('editingData.metros_cuadrados', '900')
            ->call('editRow', $second->id)->assertSet('editingData.metros_cuadrados', 'no-valido');
        $this->assertInlineRowEditor($page->html(), $second->id, ['codigo', 'metros_cuadrados', 'notas']);
        $this->assertSame([], $first->fresh()->overrides ?? []);
        $page->set('editingData.metros_cuadrados', '-1')->call('saveRow')->assertHasNoErrors()
            ->assertSet('editingRowId', null)->assertSee('2 rechazados');
        $this->assertSame(2, $import->fresh()->error_count);
        $this->assertNotEmpty($second->fresh()->errors);
        $this->assertSame('-1', $second->fresh()->overrides['metros_cuadrados']);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $xpath = new \DOMXPath($document);
        $errors = $xpath->query('//tr[@data-import-row="'.$second->id.'"]/td[contains(@class, "lf-import-preview-conflicts")]//ul/li');
        $this->assertCount(count($second->fresh()->errors), $errors);
        foreach ($second->fresh()->errors as $index => $error) {
            $this->assertSame($error, trim($errors->item($index)->textContent));
        }

        $page->call('editRow', $second->id)->assertSet('editingData.metros_cuadrados', '-1')
            ->set('editingData.metros_cuadrados', '5000')->call('saveRow')->assertHasNoErrors()
            ->assertSee('1 creaciones')->assertSee('1 rechazados');
        $this->assertSame(1, $import->fresh()->error_count);
        $this->assertSame([], $second->fresh()->errors);
        $this->assertDatabaseMissing('lotes', ['codigo' => 'SEGUNDO']);

        $first->update(['errors' => ['<script>error de fila</script>']]);
        $page->call('editRow', $first->id)
            ->assertSee('&lt;script&gt;error de fila&lt;/script&gt;', false)
            ->assertDontSee('<script>error de fila</script>', false);
        $this->assertInlineRowEditor($page->html(), $first->id, ['codigo', 'metros_cuadrados']);
    }

    public function test_inline_editor_can_correct_unmapped_required_share_fields_without_overriding_untouched_fields(): void
    {
        app(CommunitySettingsService::class)->update([
            'mode' => 'shares', 'square_meters_per_share' => '800',
            'share_precision' => 4, 'authorized_shares' => null,
        ], 'Configurar corrección', $this->admin);
        Propietario::factory()->create(['rut' => '12345678-5']);
        $page = Livewire::test(ImportShares::class)
            ->set('file', UploadedFile::fake()->createWithContent('acciones.csv', "rut\n12.345.678-5\n"))
            ->call('uploadFile')->call('preview')->assertHasNoErrors();
        $import = DataImport::query()->sole();
        $row = $import->rows()->firstOrFail();
        $page->call('editRow', $row->id);
        $this->assertInlineRowEditor($page->html(), $row->id, ['rut', 'quantity', 'amount']);
        $page->set('editingData.quantity', '10')->call('saveRow')->assertHasNoErrors()
            ->assertSee('1 creaciones')->assertSee('0 rechazados');
        $this->assertSame(['quantity' => '10'], $row->fresh()->overrides);
        $this->assertDatabaseCount('share_holdings', 0);
    }

    public function test_preview_columns_include_generated_values_and_escape_update_differences(): void
    {
        $page = Livewire::test(ImportOwners::class)
            ->set('file', UploadedFile::fake()->createWithContent('sin-correo.csv', "rut,nombre,apellido\n12.345.678-5,Ana,Torres\n"))
            ->call('uploadFile')->call('preview')->assertHasNoErrors();
        $this->assertPreviewColumns($page->html(), [
            'rut' => ['RUT', '12345678-5'],
            'email' => ['Correo electrónico', '123456785@loteofacil.cl'],
        ]);
        $page->assertDontSee('data-import-field="telefono"', false);

        $stage = Etapa::query()->create(['numero' => 87, 'nombre' => 'Cambios']);
        $before = '<b>Antes & "original"</b> '.trim(str_repeat('detalle ', 50));
        $after = '<script>Después & "nuevo"</script> '.trim(str_repeat('contenido ', 50));
        $lot = Lote::factory()->create(['etapa_id' => $stage->id, 'codigo' => 'CAMBIO', 'notas' => $before]);
        $page = Livewire::test(ImportLots::class)
            ->set('etapaId', $stage->id)->set('duplicateMode', 'update')
            ->set('file', UploadedFile::fake()->createWithContent('cambios.csv', "codigo,notas\nCAMBIO,\"".str_replace('"', '""', $after)."\"\n"))
            ->call('uploadFile')->call('preview')->assertHasNoErrors()
            ->assertSee('title="'.e(trim($before)).'"', false)
            ->assertSee('title="'.e(trim($after)).'"', false)
            ->assertDontSee('<script>Después', false);
        $this->assertTruncatedPreviewValues($page->html(), trim($before), trim($after));
        $this->assertPreviewColumns($page->html(), [
            'codigo' => ['Código', 'CAMBIO'],
            'notas' => ['Notas', trim($before).' → '.trim($after)],
        ]);
        $page->call('review')->assertSet('step', 4);
        $this->assertTruncatedPreviewValues($page->html(), trim($before), trim($after));
        $page->set('confirmed', true)->call('execute')->assertHasNoErrors();
        $this->assertTruncatedPreviewValues($page->html(), trim($before), trim($after));
        $this->assertSame(trim($after), $lot->refresh()->notas);
        $row = DataImport::query()->where('type', 'lots')->sole()->rows()->sole();
        $this->assertSame(trim($after), $row->data['notas']);
        $this->assertSame($before, $row->changes['notas']['before']);
    }

    private function assertTruncatedPreviewValues(string $html, string $before, string $after): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $values = $xpath->query('//td[@data-import-field="notas"]/span[contains(@class, "lf-import-preview-value")]');
        $this->assertCount(2, $values);
        foreach ([$before, $after] as $index => $value) {
            $this->assertSame($value, trim($values->item($index)->textContent));
            $this->assertSame($value, trim($values->item($index)->getAttribute('title')));
        }
        $this->assertStringContainsString('lf-import-preview-before', $values->item(0)->getAttribute('class'));
        $this->assertMatchesRegularExpression('/\.lf-import \.lf-import-preview-table td > \.lf-import-preview-value\s*\{[^}]*display: block;[^}]*width: 18rem;[^}]*overflow: hidden;[^}]*text-overflow: ellipsis;[^}]*white-space: nowrap;/s', $html);
        $this->assertCount(0, $xpath->query('//td[contains(@class, "lf-import-preview-conflicts")]//*[contains(@class, "lf-import-preview-value")]'));
    }

    public function test_owner_cannot_access_import_pages(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('propietario');
        $this->actingAs($owner);
        $this->assertFalse(ImportOwners::canAccess());
        $this->assertFalse(ImportLots::canAccess());
        $this->assertFalse(ImportHistory::canAccess());
        $this->get(ImportOwners::getUrl())->assertForbidden();
        $this->get(ImportLots::getUrl())->assertForbidden();
        $this->get(ImportHistory::getUrl())->assertForbidden();
    }

    public function test_execution_columns_keep_changes_and_show_execution_errors_instead_of_planned_actions(): void
    {
        $stage = Etapa::query()->create(['numero' => 88, 'nombre' => 'Conflicto de ejecución']);
        $lot = Lote::factory()->create(['etapa_id' => $stage->id, 'codigo' => 'CONFLICTO', 'notas' => '<b>Antes</b>']);
        $page = Livewire::test(ImportLots::class)
            ->set('etapaId', $stage->id)->set('duplicateMode', 'update')
            ->set('file', UploadedFile::fake()->createWithContent('resultados.csv', "codigo,notas,metros_cuadrados\nCONFLICTO,<script>Despues</script>,\nNUEVO-RESULTADO,Creado,5000\n"))
            ->call('uploadFile')->call('preview')->call('review')->assertHasNoErrors()
            ->assertSet('step', 4)->assertSee('Actualizar');
        $this->assertPreviewColumns($page->html(), [
            'codigo' => ['Código', 'CONFLICTO'],
            'notas' => ['Notas', '<b>Antes</b> → <script>Despues</script>'],
        ]);
        $this->assertExecutionIsReadOnly($page->html());
        $this->assertSame('<b>Antes</b>', $lot->refresh()->notas);
        $lot->update(['notas' => 'Modificación concurrente']);

        $page->set('confirmed', true)->call('execute')->assertHasNoErrors()
            ->assertSee('Importación completada con errores')
            ->assertSee('El registro cambió desde la previsualización. Vuelva a importar.')
            ->assertSee('Rechazar')->assertDontSee('Actualizar')
            ->assertSee('&lt;b&gt;Antes&lt;/b&gt;', false)
            ->assertSee('&lt;script&gt;Despues&lt;/script&gt;', false)
            ->assertDontSee('<script>Despues</script>', false);
        $this->assertPreviewColumns($page->html(), [
            'codigo' => ['Código', 'CONFLICTO'],
            'notas' => ['Notas', '<b>Antes</b> → <script>Despues</script>'],
        ]);
        $this->assertExecutionIsReadOnly($page->html());
        $import = DataImport::query()->sole();
        $this->assertSame('completed_with_errors', $import->status);
        $this->assertSame(1, $import->created_count);
        $this->assertSame(0, $import->updated_count);
        $this->assertSame(1, $import->error_count);
        $row = $import->rows()->orderBy('number')->firstOrFail();
        $this->assertSame('update', $row->action);
        $this->assertSame('error', $row->result);
        $this->assertSame('Modificación concurrente', $lot->refresh()->notas);
        $this->assertDatabaseHas('lotes', ['codigo' => 'NUEVO-RESULTADO']);
        $page->call('downloadReport', true)->assertFileDownloaded("importacion-{$import->id}-errores.csv");
    }

    public function test_wizard_rejects_invalid_file_and_community_before_creating_history(): void
    {
        Livewire::test(ImportLots::class)
            ->set('communityId', 999)
            ->set('file', UploadedFile::fake()->createWithContent('datos.json', '{"foo":"bar"}'))
            ->call('uploadFile')->assertHasErrors(['communityId', 'file']);
        $this->assertDatabaseCount('data_imports', 0);
    }

    public function test_account_catalog_only_lists_eligible_accounts_and_neutralizes_spreadsheet_formulas(): void
    {
        $account = User::factory()->create(['name' => '=1+1']);
        $account->assignRole('propietario');
        $response = app(ImportFileDownloads::class)->eligibleAccounts($this->admin);
        $csv = TestResponse::fromBaseResponse($response)->streamedContent();
        $this->assertStringContainsString($account->email, $csv);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringNotContainsString($this->admin->email, $csv);
    }

    public function test_execution_uses_server_preview_not_later_client_mapping_or_scope_changes(): void
    {
        $stage = Etapa::query()->create(['numero' => 82, 'nombre' => 'Ámbito validado']);
        $otherStage = Etapa::query()->create(['numero' => 83, 'nombre' => 'Otro ámbito']);
        Livewire::test(ImportLots::class)
            ->set('etapaId', $stage->id)
            ->set('file', UploadedFile::fake()->createWithContent('servidor.csv', "codigo,metros_cuadrados\nSERVER-001,6000\n"))
            ->call('uploadFile')->call('preview')->assertHasNoErrors()
            ->set('mapping', ['codigo' => 1, 'metros_cuadrados' => 0])
            ->set('etapaId', $otherStage->id)
            ->set('communityId', 999)
            ->call('review')->set('confirmed', true)->call('execute')->assertHasNoErrors();
        $this->assertDatabaseHas('lotes', ['codigo' => 'SERVER-001', 'metros_cuadrados' => 6000, 'etapa_id' => $stage->id]);
        $this->assertDatabaseMissing('lotes', ['codigo' => '6000']);
    }

    public function test_preview_without_rut_mapping_stays_on_step_with_inline_error(): void
    {
        Livewire::test(ImportOwners::class)
            ->set('file', UploadedFile::fake()->createWithContent('propietarios.csv', "nombre,apellido,email\nAna,Perez,ana@perez.cl\n"))
            ->call('uploadFile')->assertHasNoErrors()
            ->set('mapping', ['rut' => '', 'nombre' => 0, 'apellido' => 1, 'email' => 2])
            ->call('preview')->assertHasErrors(['mapping'])
            ->assertSet('step', 2)
            ->assertSee('Mapee RUT/código y no reutilice columnas.');
    }

    public function test_execute_without_confirmation_stays_on_step_with_inline_error(): void
    {
        Livewire::test(ImportOwners::class)
            ->set('file', UploadedFile::fake()->createWithContent('propietarios.csv', "rut,nombre,apellido,email\n11.222.333-9,Ana,Perez,ana2@perez.cl\n"))
            ->call('uploadFile')->call('preview')->call('review')
            ->call('execute')->assertHasErrors(['confirmed'])
            ->assertSet('step', 4)
            ->assertSee('Debe confirmar explícitamente la ejecución.');
        $this->assertDatabaseMissing('propietarios', ['rut' => '11222333-9']);
    }

    public function test_owner_execution_notifies_final_counts(): void
    {
        Livewire::test(ImportOwners::class)
            ->set('file', UploadedFile::fake()->createWithContent('propietarios.csv', "rut,nombre,apellido,email\n11.222.333-9,Ana,Perez,ana3@perez.cl\n33.444.555-6,Luis,Soto,correonovalido\n"))
            ->call('uploadFile')->call('preview')->call('review')
            ->set('confirmed', true)->call('execute')->assertHasNoErrors()
            ->assertNotified('Importación completada con errores');
        $this->assertDatabaseHas('propietarios', ['rut' => '11222333-9']);
        $import = DataImport::query()->sole();
        $this->assertSame(1, $import->created_count);
        $this->assertSame(1, $import->error_count);
    }
}

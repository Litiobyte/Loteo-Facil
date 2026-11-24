<x-filament-panels::page>
    @include('filament.admin.pages.imports.styles')
    @php
        $wizardSteps = [
            1 => ['label' => 'Seleccionar archivo', 'description' => 'Cargar CSV o Excel'],
            2 => ['label' => 'Mapear columnas', 'description' => 'Relacionar campos'],
            3 => ['label' => 'Validar y previsualizar', 'description' => 'Revisar registros'],
            4 => ['label' => 'Ejecutar importación', 'description' => 'Guardar en el sistema'],
        ];
        $importIsFinished = $import && in_array($import->status, ['completed', 'completed_with_errors'], true);
        $progressPercentage = $importIsFinished ? 100 : (int) round((($step - 1) / 3) * 100);
        $progressTrackPercentage = (($step - 1) / 4) * 100;
    @endphp

    <div class="lf-import grid min-w-0 w-full gap-6" data-import-step="{{ $step }}">
        <div class="lf-import-intro">
            <span class="lf-import-intro-icon" aria-hidden="true">
                <x-filament::icon :icon="match ($type) { 'owners' => 'heroicon-o-user-group', 'shares' => 'heroicon-o-chart-pie', default => 'heroicon-o-map' }" class="h-7 w-7" />
            </span>
            <p class="text-sm text-gray-500 dark:text-gray-400">Asistente de importación de {{ match ($type) { 'owners' => 'propietarios', 'shares' => 'saldos de acciones', 'financial' => 'movimientos financieros históricos', default => 'lotes' } }} desde un archivo CSV o Excel.</p>
        </div>
        <div class="lf-import-stepper">
            <div class="lf-import-stepper-content">
                <div class="lf-import-stepper-summary">
                    <x-filament::badge :color="$importIsFinished ? 'success' : 'primary'" icon="heroicon-o-arrow-path">
                        Paso {{ $step }} de 4
                    </x-filament::badge>
                    <span class="lf-import-stepper-percentage">
                        {{ $progressPercentage }}% del recorrido
                    </span>
                </div>

                <nav aria-label="Pasos de importación">
                    <div class="lf-import-stepper-track">
                        <div
                            class="lf-import-stepper-line"
                            aria-hidden="true"
                        ></div>
                        <div
                            class="lf-import-stepper-progress"
                            style="width: {{ $progressTrackPercentage }}%;"
                            role="progressbar"
                            aria-label="Progreso del asistente"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow="{{ $progressPercentage }}"
                            aria-valuetext="Paso {{ $step }} de 4"
                        ></div>

                        <ol class="lf-import-stepper-steps">
                            @foreach ($wizardSteps as $number => $wizardStep)
                                @php
                                    $isStepCompleted = $number < $step || ($number === 4 && $importIsFinished);
                                    $isStepActive = $number === $step && ! $isStepCompleted;
                                    $stepState = $isStepCompleted ? 'completed' : ($isStepActive ? 'active' : 'pending');
                                    $stepStateLabel = $isStepCompleted ? 'Completado' : ($isStepActive ? 'En curso' : 'Pendiente');
                                @endphp
                                <li
                                    class="lf-import-stepper-step"
                                    data-step="{{ $number }}"
                                    data-step-state="{{ $stepState }}"
                                    @if ($isStepActive || ($number === $step && $importIsFinished)) aria-current="step" @endif
                                >
                                    <span class="lf-import-stepper-circle">
                                        @if ($isStepCompleted)
                                            <x-filament::icon icon="heroicon-o-check" class="lf-import-stepper-check" aria-hidden="true" />
                                        @else
                                            <span aria-hidden="true">{{ $number }}</span>
                                        @endif
                                    </span>
                                    <span class="lf-import-stepper-caption">
                                        <span class="lf-import-stepper-label">
                                            {{ $wizardStep['label'] }}
                                        </span>
                                        <span class="lf-import-stepper-description">
                                            {{ $isStepCompleted ? 'Completado' : $wizardStep['description'] }}
                                        </span>
                                        <span class="lf-import-stepper-sr-only">Estado: {{ $stepStateLabel }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </nav>
            </div>
        </div>

        <div class="lf-import-panel">
        <x-filament::callout
            color="info"
            icon="heroicon-o-information-circle"
            :heading="'Importación de '.match ($type) { 'owners' => 'propietarios', 'shares' => 'saldos de acciones', 'financial' => 'movimientos financieros históricos', default => 'lotes' }"
            :description="match ($type) { 'financial' => 'Solo super_admin. Ejecución atómica: si una fila falla, se revierte todo el lote. No hay reversión destructiva.', 'shares' => 'Una fila por propietario existente: saldo consolidado de acciones y aporte histórico opcional.', default => 'No importa acciones, aportes, saldos, asignaciones ni movimientos financieros.' }"
        >
            <x-slot:footer>
                <details class="lf-import-considerations">
                    <summary>Consideraciones de importación</summary>
                <div class="grid gap-2 text-sm leading-6">
                    <p>Las plantillas contienen ejemplos: reemplácelos por datos reales antes de cargar el archivo.</p>
                    @if ($type === 'owners')
                        <p>Cada propietario nuevo requiere nombre, apellido, RUT y correo. Si la columna user_id trae el ID de una cuenta con rol propietario y sin perfil vinculado, se usa esa cuenta; si viene vacía, se crea una cuenta con rol propietario y contraseña inicial RUT+apellido (por ejemplo 12345678-5perez), que debe cambiarse en el primer ingreso al portal. Las celdas vacías nunca borran información.</p>
                    @elseif ($type === 'shares')
                        <p>Importe primero los propietarios. Use rut, quantity, amount, reference_date y notes. Los números usan punto decimal sin separadores de miles; las fechas usan AAAA-MM-DD. Un aporte vacío significa desconocido; 0 significa un aporte conocido de cero.</p>
                        <p>Las nuevas tenencias serán activas con la equivalencia m²/acción vigente. La fecha es de referencia del saldo, no reconstruye adquisiciones individuales. Si ya existen tenencias, solo puede conciliar un aporte conocido cuya cantidad coincida con el total, incluidas las inactivas; se conservan sus fechas y equivalencias.</p>
                        <p>No se sobrescriben aportes existentes. Los saldos idénticos ya importados se omiten, independientemente del modo seleccionado; los contradictorios se rechazan. Cada fila es atómica: las válidas se guardan y las fallidas se detallan en el reporte. No importa comprobantes, cuotas ni pagos de gastos comunes.</p>
                    @elseif ($type === 'financial')
                        @if ($this->financialType === 'openings')
                        <p>Una fila por saldo inicial: source, movement_type=initial_opening, external_id, propietario_id, cutoff_date, opening_debt, opening_credit e information_source son obligatorios. Indique deuda o crédito mayor que cero y use 0 en el otro campo; nunca ambos.</p>
                        <p>cutoff_date es la fecha diaria de corte. information_source debe describir el respaldo de donde proviene el saldo. Concepto, referencia y observaciones son opcionales.</p>
                        @elseif ($this->financialType === 'collections')
                        <p>Una fila por recaudación documentada: source, movement_type=collection, external_id, propietario_id, collection_date, amount, collection_method y mode. Puede usar un mismo modo en toda la importación o mezclar modos por fila.</p>
                        @elseif ($this->financialType === 'expenses')
                        <p>Una fila por gasto: source, movement_type=expense, external_id, title, expense_category_id, expense_date, amount, distribution_type y mode. Categoría y proveedor usan IDs existentes. Modalidades: register_only (solo registrar), distribute (datos actuales) y explicit (cobros históricos explícitos).</p>
                        <p>La columna charges contiene una lista JSON; cada objeto requiere external_id, propietario_id y amount como texto. En CSV entrecomille toda la celda y duplique las comillas internas; en XLSX use una celda de texto. Puede corregir el JSON antes de ejecutar.</p>
                        @elseif ($this->financialType === 'charges')
                        <p>Una fila por cobro: source, movement_type=expense_charge, external_id, mode, propietario_id, amount positivo y origin_date. Elija la modalidad en la columna mode de cada fila; puede mezclar modalidades en un archivo. reported_paid y reported_remaining solo informan conciliación: nunca crean pagos.</p>
                        @else
                        <p>Campos obligatorios: source, movement_type, external_id, propietario_id, amount y effective_date. Use el ID de un propietario existente. Opcionales: charge_id, reference y observations.</p>
                        <p>Tipos admitidos: opening_balance, debt_correction, debt_forgiveness y unexplained_difference. Corrección y condonación requieren charge_id. Las aperturas positivas son deuda; las negativas, crédito. No importa gastos ni recaudaciones.</p>
                        @endif
                        <p>Fechas diarias AAAA-MM-DD. Montos decimales sin miles (1000.00) o formato chileno inequívoco (1.000,00); evite 1.000 por ambiguo. Los períodos cerrados se rechazan. La identidad source + movement_type + external_id es estable: no se sobrescribe contenido contradictorio.</p>
                    @else
                        <p>La etapa se selecciona aquí; el código de lote es único en toda la instalación. Para lotes nuevos use superficie entera positiva en m² y estado disponible, reservado o vendido. En existentes solo se actualizan notas: cambios de superficie o estado se rechazan para proteger la cobranza. No es una importación geográfica KMZ/KML.</p>
                    @endif
                </div>
                </details>
            </x-slot:footer>
        </x-filament::callout>

        @if ($type === 'financial' && $this->financialType === 'openings')
            <x-filament::callout color="info" icon="heroicon-o-information-circle" heading="Fuente de información obligatoria y dirección del saldo">
                <x-slot:description>
                    <p>Describa en information_source el libro, planilla, sistema o respaldo usado para establecer cada saldo inicial.</p>
                    <p>Si opening_debt y opening_credit son mayores que cero, la fila se rechaza: indique una sola dirección, sin calcular un neto. Si ambos son cero, la fila queda omitida y no genera movimiento.</p>
                </x-slot:description>
            </x-filament::callout>
            <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle"
                heading="Apertura financiera sin movimiento de caja"
                description="Una apertura no representa un gasto ni una recaudación nueva, no crea esos movimientos y no modifica la caja. El saldo deudor establece deuda inicial y el acreedor genera crédito utilizable. El corte inclusivo y los períodos cerrados se validan al ejecutar." />
        @endif

        @if ($type === 'financial' && $this->financialType === 'expenses')
            <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle"
                heading="Reconstrucción aproximada, no historia verificada"
                description="La modalidad distribute usa participaciones y datos vigentes, no verifica la distribución histórica. Exige distribuir_con_datos_actuales=sí en cada fila, sin valor por defecto. Si no puede confirmarlo, use register_only o explicit." />
            <x-filament::callout color="info" icon="heroicon-o-information-circle" heading="Revisar cobros explícitos">
                <x-slot:description>
                    Ejemplo de celda charges: <code>[{"external_id":"COBRO-1","propietario_id":123,"amount":"1000.00"}]</code>.
                    Revise que los montos cuadren con el gasto: el servicio rechaza una suma superior; admite una suma menor como información histórica incompleta, sin completar ni redistribuir la diferencia. No se importan pagos ni recaudaciones.
                </x-slot:description>
            </x-filament::callout>
        @endif

        @if ($type === 'financial' && $this->financialType === 'charges')
            <x-filament::callout color="info" icon="heroicon-o-information-circle" heading="Modalidades de cobros históricos">
                <x-slot:description>
                    <p>linked — Vinculado: indique expense_id o expense_external_id de un gasto existente; expense_source es opcional. Si indica ambas referencias, deben coincidir.</p>
                    <p>backing — Gasto de respaldo histórico: exige expense_external_id nuevo, expense_category_id y title. No redistribuye el gasto.</p>
                    <p>opening — Apertura: origin_date es el corte inclusivo; no admite gasto ni vencimiento. Exige un propietario sin movimientos previos.</p>
                    <p>Los nuevos cobros parten pendientes, sin aplicaciones. Las diferencias de montos pagados o pendientes informados se muestran en el mensaje de resultado, sin alterar saldos.</p>
                </x-slot:description>
            </x-filament::callout>
            <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle"
                heading="Respaldo técnico, no total histórico acreditado"
                description="En modalidad backing el gasto de respaldo usa únicamente el monto conocido del cobro: no acredita el total histórico original del gasto. Permanece bloqueado para distribución automática." />
        @endif

        @if ($type === 'financial' && $this->financialType === 'collections')
            <x-filament::callout color="info" icon="heroicon-o-information-circle" heading="Aplicación de recaudaciones históricas">
                <x-slot:description>
                    <p>automatic — Automática: FIFO vigente; no reconstruye aplicaciones históricas. Si no hay cobros pendientes, use unapplied.</p>
                    <p>manual — Manual: indique los cobros destino y montos en allocations. Ejemplo por ID: <code>[{"charge_id":123,"amount":"1000.00"}]</code>. Por identidad externa: <code>[{"charge_external_id":"COBRO-1","charge_source":"libro","amount":"1000.00"}]</code>. Sin charge_source se usa source de la recaudación.</p>
                    <p>unapplied — Sin aplicación: deja todo el importe disponible. allocations debe estar vacío tanto en automatic como en unapplied. El servicio valida propietario, referencias y límites; un exceso manual se rechaza.</p>
                    <p>collection_method: efectivo, transferencia, cheque u otro. En CSV entrecomille la celda JSON y duplique las comillas internas; en XLSX use texto. Puede corregir modalidad y JSON antes de ejecutar.</p>
                </x-slot:description>
            </x-filament::callout>
            <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle" heading="Fecha real obligatoria y períodos protegidos">
                <x-slot:description>
                    <p>collection_date es la fecha real completa del pago; la incorporación técnica se registra por separado. Las aplicaciones importadas usan la fecha real del pago.</p>
                    <p>Si desconoce la fecha de aplicación, considere sin aplicación; aun así, registrar la recaudación exige fecha real completa conocida. No invente una fecha. Se rechazan períodos cerrados, también de cobros destino, y fechas anteriores o iguales al corte inclusivo de apertura.</p>
                </x-slot:description>
            </x-filament::callout>
        @endif

        @if ($errors->any())
            <x-filament::callout
                color="danger"
                icon="heroicon-o-x-circle"
                heading="No pudimos continuar"
                description="Revise los campos señalados y vuelva a intentarlo."
            >
                <x-slot:footer>
                    <ul role="alert" class="list-disc space-y-1 ps-5 text-sm leading-6">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-slot:footer>
            </x-filament::callout>
        @endif

        @if ($step === 1)
            <form wire:submit="uploadFile">
                <x-filament::section
                    heading="Seleccionar archivo"
                    description="Configure el ámbito y cargue un archivo para comenzar."
                    icon="heroicon-o-cloud-arrow-up"
                >
                    <div class="grid gap-6">
                        @if ($type === 'financial')
                            <fieldset>
                                <legend>Tipo de importación financiera</legend>
                                <x-filament::button type="button" :color="$this->financialType === 'adjustments' ? 'primary' : 'gray'" wire:click="selectFinancialType('adjustments')">Aperturas y ajustes</x-filament::button>
                                <x-filament::button type="button" :color="$this->financialType === 'openings' ? 'primary' : 'gray'" wire:click="selectFinancialType('openings')">Saldos iniciales</x-filament::button>
                                <x-filament::button type="button" :color="$this->financialType === 'expenses' ? 'primary' : 'gray'" wire:click="selectFinancialType('expenses')">Gastos históricos</x-filament::button>
                                <x-filament::button type="button" :color="$this->financialType === 'charges' ? 'primary' : 'gray'" wire:click="selectFinancialType('charges')">Cobros históricos</x-filament::button>
                                <x-filament::button type="button" :color="$this->financialType === 'collections' ? 'primary' : 'gray'" wire:click="selectFinancialType('collections')">Recaudaciones históricas</x-filament::button>
                            </fieldset>
                        @endif
                        <div @class(['grid gap-5', 'md:grid-cols-2' => $type === 'lots'])>
                            @if ($type === 'lots')
                                <div class="grid content-start gap-2">
                                    <label for="import-stage" class="text-sm font-medium text-gray-950 dark:text-white">Etapa</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select id="import-stage" wire:model="etapaId" required>
                                            <option value="">Seleccione una etapa</option>
                                            @foreach ($stages as $stage)
                                                <option value="{{ $stage->id }}">{{ $stage->numero }} — {{ $stage->nombre }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </div>
                            @endif
                        </div>

                        <div class="grid gap-2">
                            <label for="import-file" class="text-sm font-semibold text-gray-950 dark:text-white">Archivo de importación <span class="text-danger-600" aria-hidden="true">*</span></label>
                            <div @class(['lf-import-upload', 'lf-import-upload-loaded' => $file !== null]) data-import-upload>
                                <span class="lf-import-upload-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-cloud-arrow-up" class="h-8 w-8" /></span>
                                <strong>Seleccione su archivo para comenzar</strong>
                                <span class="text-sm text-gray-500 dark:text-gray-400">Haga clic o arrastre un archivo aquí para cargar {{ $type === 'financial' ? 'los movimientos históricos' : 'los datos administrativos' }}.</span>
                                <x-filament::badge color="gray">CSV o XLSX · Máximo 5 MB</x-filament::badge>
                            <x-filament::input.wrapper>
                                <x-filament::input id="import-file" type="file" wire:model="file" accept=".csv,.xlsx" required aria-describedby="import-file-help" />
                            </x-filament::input.wrapper>
                                <span wire:loading wire:target="file" role="status" class="text-sm text-gray-500 dark:text-gray-400">Cargando archivo…</span>
                                <span data-import-upload-error role="alert" class="text-sm text-danger-600 dark:text-danger-400" hidden></span>
                            </div>
                            <p id="import-file-help" class="text-sm leading-6 text-gray-500 dark:text-gray-400">Máximo 5 MB, 2000 filas y 64 columnas. Divida archivos mayores. CSV UTF-8 separado por coma o punto y coma; XLSX: se lee solo la primera hoja. Incluya encabezados únicos.</p>
                            @error('file')<p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        </div>

                        <div class="grid gap-3 rounded-xl border border-gray-200 p-4 dark:border-white/10 dark:bg-white/5">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Plantillas y ayuda</h3>
                                <p class="text-sm leading-6 text-gray-500 dark:text-gray-400">Descargue el formato o consulte las cuentas elegibles antes de cargar.</p>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <x-filament::button type="button" color="gray" outlined icon="heroicon-o-arrow-down-tray" wire:click="downloadTemplate('csv')">Plantilla CSV</x-filament::button>
                                @if ($type !== 'financial')
                                    <x-filament::button type="button" color="gray" outlined icon="heroicon-o-arrow-down-tray" wire:click="downloadTemplate('xlsx')">Plantilla XLSX</x-filament::button>
                                @endif
                                @if ($type === 'owners')
                                    <x-filament::button type="button" color="gray" outlined icon="heroicon-o-document-arrow-down" wire:click="downloadAccounts">Cuentas disponibles (ID/correo)</x-filament::button>
                                @endif
                            </div>
                        </div>
                    </div>

                    <x-slot:footer>
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-gray-500 dark:text-gray-400">El siguiente paso permite revisar el mapeo antes de validar.</p>
                            <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                                <x-filament::badge color="info" icon="heroicon-o-arrow-path" wire:loading wire:target="uploadFile">
                                    <span wire:loading wire:target="uploadFile" role="status">Subiendo y preparando archivo…</span>
                                </x-filament::badge>
                                <x-filament::button type="submit" icon="heroicon-o-arrow-right" icon-position="after" wire:loading.attr="disabled" wire:target="uploadFile">Continuar</x-filament::button>
                            </div>
                        </div>
                    </x-slot:footer>
                </x-filament::section>
            </form>
        @elseif ($step === 2)
            <form wire:submit="preview" class="grid gap-6">
                <x-filament::section
                    heading="Mapear columnas"
                    description="Relacione cada columna del archivo con su campo de destino."
                    icon="heroicon-o-arrows-right-left"
                >
                    <div class="grid gap-6">
                        <div class="lf-import-file">
                            <x-filament::icon icon="heroicon-o-document-text" class="h-7 w-7 shrink-0 text-primary-600 dark:text-primary-400" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $import->original_name }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $import->total }} filas detectadas · {{ count($import->headers) }} columnas disponibles</p>
                            </div>
                            <x-filament::badge color="success">Archivo cargado</x-filament::badge>
                        </div>

                        <div class="grid gap-3">
                            <div class="lf-import-mapping-head" aria-hidden="true">
                                <span>Campo de destino</span><span></span><span>Columna del archivo</span><span>Estado</span>
                            </div>

                            @foreach ($fields as $field => $label)
                                @php($isMapped = isset($mapping[$field]) && $mapping[$field] !== '')
                                <div wire:key="mapping-{{ $field }}" class="lf-import-mapping-row">
                                    <div class="grid gap-1">
                                        <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $label }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $field }}</span>
                                    </div>
                                    <x-filament::icon icon="heroicon-o-arrow-right" class="lf-import-mapping-arrow h-5 w-5 text-gray-400" aria-hidden="true" />
                                    <div class="grid gap-2">
                                        <label for="mapping-{{ $field }}" class="sr-only">Columna origen para {{ $label }}</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select id="mapping-{{ $field }}" wire:model.live="mapping.{{ $field }}">
                                                <option value="">No importar</option>
                                                @foreach ($import->headers as $index => $header)
                                                    <option value="{{ $index }}">{{ $header }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </div>

                                    <x-filament::badge :color="$isMapped ? 'success' : 'gray'" :icon="$isMapped ? 'heroicon-o-link' : 'heroicon-o-minus'">{{ $isMapped ? 'Relacionada' : 'Omitida' }}</x-filament::badge>
                                </div>
                            @endforeach
                        </div>

                        <p class="lf-import-mapping-summary" role="status">
                            <strong>{{ count(array_filter($mapping, static fn ($column) => $column !== '' && $column !== null)) }} de {{ count($fields) }} campos relacionados.</strong>
                            La asignación puede modificarse antes de validar. Las columnas no mapeadas se ignoran.
                        </p>

                        @if ($type !== 'financial')
                        <div class="grid max-w-xl gap-2 rounded-xl border border-gray-200 p-4 dark:border-white/10 dark:bg-white/5">
                            <label for="duplicates" class="text-sm font-semibold text-gray-950 dark:text-white">Registros existentes</label>
                            <p class="text-sm leading-6 text-gray-500 dark:text-gray-400">Defina cómo tratar los registros que ya existen en el sistema.</p>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="duplicates" wire:model="duplicateMode">
                                    <option value="skip">Omitir existentes</option>
                                    <option value="update">Actualizar campos permitidos no vacíos</option>
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            @error('duplicateMode')<p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        </div>
                        @else
                            <p>Las identidades existentes idénticas se omiten. Los conflictos requieren revisión manual; nunca se actualizan movimientos existentes.</p>
                        @endif

                        @error('mapping')<p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        @error('import')<p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>

                    <x-slot:footer>
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-gray-500 dark:text-gray-400">La validación no guarda registros ni modifica datos.</p>
                            <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                                <x-filament::badge color="info" icon="heroicon-o-arrow-path" wire:loading wire:target="preview">
                                    <span wire:loading wire:target="preview" role="status">Validando filas…</span>
                                </x-filament::badge>
                                <x-filament::button type="submit" icon="heroicon-o-arrow-right" icon-position="after" wire:loading.attr="disabled" wire:target="preview">Continuar</x-filament::button>
                            </div>
                        </div>
                    </x-slot:footer>
                </x-filament::section>

                <x-filament::section
                    heading="Muestra de las primeras 5 filas"
                    description="Compruebe que los encabezados y valores correspondan al archivo esperado."
                    icon="heroicon-o-table-cells"
                    compact
                >
                    <div class="lf-import-table-scroll overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10" role="region" aria-label="Muestra del archivo; desplácese horizontalmente para ver todas las columnas" tabindex="0">
                        <table class="lf-import-sample-table w-full border-collapse bg-white text-sm text-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <caption class="sr-only">Muestra de las primeras 5 filas del archivo</caption>
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th scope="col" class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Fila</th>
                                    @foreach ($import->headers as $header)
                                        <th scope="col" class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $header }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sample as $row)
                                    <tr class="border-t border-gray-200 dark:border-white/10">
                                        <th scope="row" class="px-3 py-3 text-left align-top font-medium">{{ $row->number }}</th>
                                        @foreach ($import->headers as $i => $header)
                                            <td class="px-3 py-3 align-top break-words">{{ $row->raw[$i] ?? '' }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Solo lectura · Desplácese horizontalmente para ver todas las columnas.</p>
                </x-filament::section>
            </form>
        @elseif ($step === 3 && $import)
            @include('filament.admin.pages.imports.preview')
        @elseif ($import)
            <div class="lf-import-preview">
                <section class="lf-import-preview-card" aria-labelledby="import-execution-title">
                    <header class="lf-import-preview-header">
                        <div>
                            <h2 id="import-execution-title"><x-filament::icon icon="heroicon-o-rocket-launch" aria-hidden="true" /> Ejecutar importación</h2>
                            <p>Confirme la ejecución y consulte el resumen final.</p>
                        </div>
                        <span class="lf-import-preview-filename"><x-filament::icon icon="heroicon-o-document-text" aria-hidden="true" /> {{ $import->original_name }}</span>
                    </header>
                    <div class="lf-import-preview-body">
                        <x-filament::badge :color="$import->error_count > 0 ? 'warning' : ($importIsFinished ? 'success' : 'info')" :icon="$importIsFinished ? 'heroicon-o-check-circle' : 'heroicon-o-information-circle'">
                            {{ \App\Filament\Admin\Pages\Imports\ImportHistory::statusLabel($import->status) }}
                        </x-filament::badge>

                        @if ($import->status === 'preview')
                            <x-filament::callout
                                color="warning"
                                icon="heroicon-o-exclamation-triangle"
                                heading="Confirmación pendiente"
                                description="La importación todavía no se ha ejecutado. Revise los totales y confirme explícitamente para continuar."
                            />
                        @else
                            <x-filament::callout
                                :color="$import->error_count > 0 ? 'warning' : 'success'"
                                :icon="$import->error_count > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'"
                                :heading="$import->error_count > 0 ? 'Importación completada con errores' : 'Importación completada'"
                                :description="$import->error_count > 0 ? 'La ejecución terminó. Consulte las filas rechazadas y descargue el reporte para corregirlas.' : 'La ejecución terminó correctamente. El resumen final y el detalle por fila están disponibles a continuación.'"
                            />
                        @endif

                        <div class="lf-import-preview-field">
                            <h3>
                                {{ $import->status === 'preview' ? 'Operaciones previstas' : 'Resumen final' }}
                            </h3>
                            <p class="lf-import-preview-operations">Total: {{ $import->total }} filas</p>
                        </div>
                        <dl class="lf-import-preview-stats" aria-label="{{ $import->status === 'preview' ? 'Operaciones previstas' : 'Resumen final' }}">
                            <div class="lf-import-preview-stat" data-validation-state="valid">
                                <x-filament::icon icon="heroicon-o-check-circle" aria-hidden="true" />
                                <dt>Creaciones</dt>
                                <dd>{{ $import->created_count }}</dd>
                            </div>
                            <div class="lf-import-preview-stat" data-validation-state="total">
                                <x-filament::icon icon="heroicon-o-arrow-path" aria-hidden="true" />
                                <dt>Actualizaciones</dt>
                                <dd>{{ $import->updated_count }}</dd>
                            </div>
                            <div class="lf-import-preview-stat" data-validation-state="warning">
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                                <dt>Omitidos</dt>
                                <dd>{{ $import->skipped_count }}</dd>
                            </div>
                            <div class="lf-import-preview-stat" data-validation-state="error">
                                <x-filament::icon icon="heroicon-o-x-circle" aria-hidden="true" />
                                <dt>Rechazados</dt>
                                <dd>{{ $import->error_count }}</dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <section class="lf-import-preview-card" aria-labelledby="import-execution-rows-title">
                    <header class="lf-import-preview-header">
                        <div>
                            <h2 id="import-execution-rows-title"><x-filament::icon icon="heroicon-o-table-cells" aria-hidden="true" /> Detalle por fila</h2>
                            <p>{{ $import->status === 'preview' ? 'Consulte las operaciones y los cambios previstos antes de confirmar.' : 'Consulte los resultados de ejecución y los errores por fila.' }}</p>
                        </div>
                        <span class="lf-import-preview-filename">{{ $import->total }} registros</span>
                    </header>
                    <div class="lf-import-preview-rows">
                        @include('filament.admin.pages.imports.preview-rows', ['rows' => $previewRows, 'editable' => false])
                    </div>
                    <div class="lf-import-preview-pagination">
                        <p role="status">Mostrando {{ $previewRows->firstItem() ?? 0 }}–{{ $previewRows->lastItem() ?? 0 }} de {{ $previewRows->total() }} registros</p>
                        {{ $previewRows->onEachSide(1)->links('filament.admin.pages.imports.pagination') }}
                    </div>
                </section>

                <footer class="lf-import-preview-card lf-import-preview-footer">
                    <div class="lf-import-preview-actions">
                        <x-filament::button type="button" color="gray" outlined icon="heroicon-o-arrow-down-tray" wire:click="downloadReport(false)">Descargar reporte completo</x-filament::button>
                        <x-filament::button type="button" color="gray" outlined icon="heroicon-o-arrow-down-tray" wire:click="downloadReport(true)">Descargar errores</x-filament::button>
                    </div>
                    @if ($import->status === 'preview')
                        <form wire:submit="execute" class="lf-import-preview-confirmation">
                            <div class="lf-import-preview-field">
                                <p>{{ $type === 'financial' ? 'Se confirma el lote completo de forma atómica. Si una fila falla, no se registra ningún movimiento nuevo. No hay reversión destructiva.' : 'Solo se ejecutarán filas válidas; las rechazadas permanecerán en el reporte. No hay eliminación ni reversión masiva.' }}</p>
                                <div class="lf-import-execution-confirmed">
                                    <x-filament::input.checkbox id="import-confirmed" wire:model="confirmed" />
                                    <label for="import-confirmed" class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Confirmo las creaciones y actualizaciones de esta previsualización.</label>
                                </div>
                                @error('confirmed')<p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                            </div>

                            <div wire:loading wire:target="execute" role="status">
                                <span>Procesamiento en curso. No cierre esta página.</span>
                            </div>

                            <div class="lf-import-preview-actions">
                                <p>Paso final: confirme para ejecutar la importación.</p>
                                <x-filament::badge color="info" icon="heroicon-o-arrow-path" wire:loading wire:target="execute">
                                    <span wire:loading wire:target="execute" role="status">Ejecutando importación…</span>
                                </x-filament::badge>
                                <x-filament::button type="submit" icon="heroicon-o-rocket-launch" wire:loading.attr="disabled" wire:target="execute">Ejecutar importación</x-filament::button>
                            </div>
                        </form>
                    @else
                        <p>Ejecución finalizada. Los reportes permanecen disponibles para su revisión.</p>
                        @if ($type === 'financial' && $hasConflictRows)
                            <p role="alert">Hay conflictos de identidad: requieren revisión manual. No se sobrescriben ni se reintentan automáticamente.</p>
                        @endif
                        @if ($type === 'financial' && auth()->user()->hasRole('super_admin') && $hasFailedRows)
                            <form wire:submit="retryFailed" wire:confirm="¿Reintentar únicamente las filas fallidas? Los resultados confirmados no se modificarán.">
                                <x-filament::input.checkbox id="import-retry-confirmed" wire:model="confirmed" />
                                <label for="import-retry-confirmed">Confirmo el reintento de las filas fallidas.</label>
                                @error('confirmed')<p role="alert">{{ $message }}</p>@enderror
                                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="retryFailed">Reintentar filas fallidas</x-filament::button>
                            </form>
                        @endif
                    @endif
                </footer>
            </div>
        @endif
        </div>
    </div>

    @script
        <script>
            const importRoot = $wire.$el;

            for (const eventName of ['click', 'dragover', 'dragleave', 'drop']) {
                importRoot.addEventListener(eventName, (event) => {
                    const container = event.target.closest('[data-import-upload]');
                    if (! container) return;

                    const input = container.querySelector('input[type="file"]');
                    if (! input || input.disabled) return;

                    if (eventName === 'click') {
                        if (event.target !== input) input.click();
                        return;
                    }

                    if (eventName === 'dragleave') {
                        if (! container.contains(event.relatedTarget)) {
                            container.classList.remove('lf-import-upload-dragover');
                        }
                        return;
                    }

                    event.preventDefault();

                    if (eventName === 'dragover') {
                        event.dataTransfer.dropEffect = 'copy';
                        container.classList.add('lf-import-upload-dragover');
                        return;
                    }

                    container.classList.remove('lf-import-upload-dragover');
                    const file = event.dataTransfer.files[0];
                    if (! file) return;

                    const error = container.querySelector('[data-import-upload-error]');
                    error.hidden = true;
                    error.textContent = '';

                    try {
                        const transfer = new DataTransfer();
                        transfer.items.add(file);
                        input.files = transfer.files;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    } catch {
                        error.textContent = 'No se pudo cargar el archivo arrastrado. Use el selector de archivos.';
                        error.hidden = false;
                    }
                });
            }
        </script>
    @endscript
</x-filament-panels::page>

<x-filament-panels::page>
    @include('filament.owner.pages.partials.mi-perfil-styles')

    <div class="op-profile">
        <header class="op-heading">
            <span class="op-heading-icon"><x-filament::icon icon="heroicon-o-user" /></span>
            <div><h1>Mi perfil</h1><p>Actualiza tus datos y ayuda a mantener al día los registros de tu comunidad.</p></div>
        </header>

        @if (! $owner)
            <section class="op-card op-body">
                <h2>No tienes un propietario asociado</h2>
                <p>Contacta a la administración para vincular tu cuenta. No es posible guardar datos hasta entonces.</p>
            </section>
        @else
            <div class="op-layout">
                <div class="op-stack">
                    <section class="op-card op-body op-identity">
                        <span class="op-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($owner->nombre, 0, 1).\Illuminate\Support\Str::substr($owner->apellido, 0, 1)) }}</span>
                        <div><strong>{{ $displayName }}</strong><p>Propietario · {{ $accountEmail }}</p><span class="op-pill">Cuenta activa</span></div>
                    </section>

                    <form wire:submit="saveProfile" class="op-stack">
                        <fieldset class="op-stack" @disabled(! auth()->user()->can('updateProfile', $owner))>
                        @foreach (['Datos personales' => ['nombre', 'apellido', 'rut', 'profesion', 'nacionalidad'], 'Información de contacto' => ['email', 'telefono', 'direccion', 'region_id', 'comuna_id']] as $section => $sectionFields)
                            <section class="op-card">
                                <div class="op-card-head">
                                    <div><h2>{{ $section }}</h2><p>{{ $loop->first ? 'Información para identificar correctamente tu participación.' : 'Facilita que la administración pueda comunicarse contigo.' }}</p></div>
                                    <x-filament::icon :icon="$loop->first ? 'heroicon-o-user' : 'heroicon-o-envelope'" />
                                </div>
                                <div class="op-body op-grid">
                                    @foreach ($sectionFields as $field)
                                        <div @class(['op-field', 'op-full' => $field === 'direccion', 'op-missing' => isset($missing[$field])]) wire:key="profile-{{ $field }}">
                                            <label for="profile-{{ $field }}">{{ $fields[$field] }} @if (in_array($field, ['nombre', 'apellido', 'rut', 'email']))<span class="op-required">*</span>@endif</label>
                                            @if (in_array($field, ['region_id', 'comuna_id']))
                                                <select id="profile-{{ $field }}" wire:model.live="data.{{ $field }}" @disabled($field === 'comuna_id' && blank($data['region_id'] ?? null)) aria-describedby="error-{{ $field }}" @error("data.$field") aria-invalid="true" @enderror>
                                                    <option value="">Seleccionar {{ $field === 'region_id' ? 'región' : 'comuna' }}...</option>
                                                    @foreach (($field === 'region_id' ? $regions : $communes) as $id => $name)
                                                        <option value="{{ $id }}">{{ $name }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input id="profile-{{ $field }}" wire:model.live.debounce.400ms="data.{{ $field }}"
                                                    type="{{ $field === 'email' ? 'email' : ($field === 'telefono' ? 'tel' : 'text') }}"
                                                    maxlength="{{ $field === 'rut' ? 20 : (in_array($field, ['profesion', 'nacionalidad']) ? 120 : ($field === 'telefono' ? 50 : 255)) }}"
                                                    autocomplete="{{ ['nombre' => 'given-name', 'apellido' => 'family-name', 'email' => 'email', 'telefono' => 'tel', 'direccion' => 'street-address'][$field] ?? 'off' }}"
                                                    @required(in_array($field, ['nombre', 'apellido', 'rut', 'email']))
                                                    aria-describedby="error-{{ $field }}" @error("data.$field") aria-invalid="true" @enderror>
                                            @endif
                                            @error("data.$field")<span class="op-error" id="error-{{ $field }}" role="alert">{{ $message }}</span>@enderror
                                            @if ($field === 'rut')<small>Identificador del propietario, validado con dígito verificador.</small>@endif
                                            @if ($field === 'email')<small>Este correo es de contacto; no cambia tu correo de acceso.</small>@endif
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                        </fieldset>
                        @can('updateProfile', $owner)
                        <div class="op-card op-actions">
                            <span role="status">
                                @if ($saved) Tus cambios se guardaron correctamente.
                                @else Los cambios se guardan únicamente al presionar el botón.
                                @endif
                            </span>
                            <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled" wire:target="saveProfile">Guardar cambios</x-filament::button>
                        </div>
                        @else
                            <p>Tu cuenta tiene acceso de solo lectura a este perfil.</p>
                        @endcan
                    </form>
                </div>

                <aside class="op-stack">
                    <section class="op-card op-body" aria-label="Completitud del perfil">
                        <div class="op-progress-top"><strong>{{ $percentage === 100 ? 'Tu perfil está completo' : 'Completa tu perfil' }}</strong><span>{{ $percentage }}%</span></div>
                        <div class="op-progress" role="progressbar" aria-label="Completitud del perfil" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percentage }}"><div style="width: {{ $percentage }}%"></div></div>
                        <p>Ya completaste {{ $completed }} de {{ count($fields) }} campos clave.</p>
                        <div class="op-motivation">
                            {{ $percentage === 100 ? '¡Excelente! Mantén tus datos al día cuando cambien.' : ($percentage >= 70 ? '¡Ya casi terminas! Solo faltan algunos datos para completar tu perfil.' : 'Completar tu información facilita la comunicación y evita errores administrativos.') }}
                        </div>
                    </section>
                    <section class="op-card op-body">
                        <h2 class="op-section-label">Campos que puedes completar</h2>
                        <div class="op-todo">
                            @forelse ($missing as $field => $label)
                                <a href="#profile-{{ $field }}" x-on:click.prevent="const field = document.getElementById('profile-{{ $field }}'); field?.scrollIntoView({ behavior: 'smooth', block: 'center' }); field?.focus({ preventScroll: true })">
                                    <span class="op-todo-icon" aria-hidden="true">!</span>
                                    <span class="op-todo-label">{{ $label }}</span><span>Completar →</span>
                                </a>
                            @empty
                                <p>¡No tienes datos pendientes!</p>
                            @endforelse
                        </div>
                        <p class="op-note">El porcentaje refleja los campos llenos del formulario, incluidos los recomendados. Los datos opcionales no impiden guardar. Guarda para conservar tus cambios.</p>
                    </section>
                    <section class="op-tip"><strong>¿Para qué completar estos datos?</strong><p>Ayudan a mantener un registro actualizado de propietarios, reducir errores en documentos y facilitar las comunicaciones de la comunidad.</p></section>
                    <section class="op-card op-body op-security">
                        <x-filament::icon icon="heroicon-o-lock-closed" />
                        <div><strong>Tus datos personales</strong><p>Solo puedes consultar y actualizar tu propio perfil desde este portal. Los cambios se guardan de forma segura en los registros de la comunidad.</p></div>
                    </section>
                </aside>
            </div>
        @endif
    </div>
</x-filament-panels::page>

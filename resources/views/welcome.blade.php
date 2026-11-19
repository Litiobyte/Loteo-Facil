<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Loteo Fácil: consulta tus cobros, pagos registrados, estado de cuenta y participación en tu comunidad desde un solo lugar.">
        <meta name="theme-color" content="#1e6432">
        <title>Loteo Fácil · Tu comunidad, más clara</title>
        @fonts
        @vite('resources/css/app.css')
    </head>
    <body class="bg-(--lf-bg) font-sans text-(--lf-text) antialiased selection:bg-green-100">
        <a href="#contenido" class="sr-only z-50 rounded-lg bg-white p-4 text-(--lf-blue) focus:not-sr-only focus:fixed focus:top-4 focus:left-4">Saltar al contenido</a>

        <header class="border-b border-(--lf-border) bg-white">
            <nav aria-label="Navegación principal" class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-5 px-6 py-5 lg:px-10">
                @php($landingLogoUrl = \App\Models\CommunitySetting::logoUrl())
                <a href="{{ url('/') }}" aria-label="{{ \App\Models\CommunitySetting::displayName() }}, inicio" class="flex items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-(--lf-focus)">
                    <img src="{{ $landingLogoUrl }}" alt="{{ \App\Models\CommunitySetting::displayName() }}" class="h-11 w-auto sm:h-12">
                    @if ($landingLogoUrl !== asset('branding/logo-full-transparent.png'))
                        <span class="text-lg font-bold tracking-tight text-(--lf-blue)">{{ \App\Models\CommunitySetting::displayName() }}</span>
                    @endif
                </a>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-4 text-sm font-semibold">
                    <a href="#beneficios" class="rounded-sm text-(--lf-text-muted) hover:text-(--lf-green) focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-(--lf-focus)">Qué puedes consultar</a>
                    <a href="{{ route('filament.owner.auth.login') }}" class="rounded-lg bg-(--lf-green) px-5 py-3 text-white hover:bg-(--lf-primary-hover) focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-(--lf-focus)">Acceso propietarios <span aria-hidden="true">↗</span></a>
                </div>
            </nav>
        </header>

        <main id="contenido">
            <section aria-labelledby="titulo" class="relative isolate overflow-hidden">
                <div aria-hidden="true" class="pointer-events-none absolute -top-36 -right-44 -z-10 size-[40rem] rounded-full border-[80px] border-(--lf-surface-subtle)"></div>
                <div class="mx-auto grid max-w-7xl items-center gap-14 px-6 py-16 lg:grid-cols-2 lg:gap-20 lg:px-10 lg:py-24">
                    <div class="flex flex-col items-start gap-7">
                        <p class="inline-flex items-center gap-2 rounded-full border border-(--lf-border) bg-white px-4 py-2 text-xs font-semibold tracking-wide text-(--lf-green)">
                            <span aria-hidden="true" class="size-2 rounded-full bg-(--lf-green)"></span>
                            EL PORTAL DE TU COMUNIDAD
                        </p>
                        <h1 id="titulo" class="max-w-xl text-4xl leading-tight font-bold tracking-tight text-(--lf-blue) sm:text-5xl lg:text-6xl">
                            Tu comunidad,<br>
                            <span class="text-(--lf-green)">más clara.</span><br>
                            Tu información,<br>más cerca.
                        </h1>
                        <p class="max-w-lg text-base leading-8 text-(--lf-text-muted) sm:text-lg">
                            Revisa tus cobros, los pagos registrados y tu estado de cuenta. Toda la información de tu participación, en un solo lugar.
                        </p>
                        <div class="flex flex-wrap items-center gap-5">
                            <a href="{{ route('filament.owner.auth.login') }}" class="inline-flex items-center gap-4 rounded-xl bg-(--lf-green) px-6 py-4 text-sm font-semibold text-white shadow-sm hover:bg-(--lf-primary-hover) focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-(--lf-focus)">
                                Ingresar a mi portal <span aria-hidden="true">→</span>
                            </a>
                            <a href="#beneficios" class="rounded-sm text-sm font-semibold text-(--lf-blue) underline decoration-(--lf-border) underline-offset-4 hover:decoration-(--lf-blue) focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-(--lf-focus)">Conocer el portal</a>
                        </div>
                        <p class="text-xs leading-6 text-(--lf-text-muted)">Accede con la cuenta que te entregó la administración.</p>
                    </div>

                    <div class="relative">
                        <div class="overflow-hidden rounded-3xl border border-(--lf-border) bg-white shadow-xl shadow-green-950/5">
                            <div class="flex items-center justify-between gap-4 border-b border-(--lf-border) bg-(--lf-surface-subtle) px-6 py-4">
                                <span class="text-sm font-semibold text-(--lf-green)">Mi portal propietario</span>
                                <span class="rounded-full bg-white px-3 py-1 text-xs text-(--lf-text-muted)">Vista ilustrativa</span>
                            </div>
                            <div class="flex flex-col gap-7 p-6 sm:p-8">
                                <div>
                                    <p class="text-xs font-semibold tracking-widest text-(--lf-text-muted)">TODO EN UN MISMO LUGAR</p>
                                    <h2 class="mt-2 text-2xl font-bold tracking-tight text-(--lf-blue)">Información para estar al día</h2>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="rounded-xl border border-(--lf-border) bg-(--lf-bg) p-4">
                                        <span aria-hidden="true" class="text-xl text-(--lf-green)">↗</span>
                                        <p class="mt-3 text-sm font-semibold">Mis cobros</p>
                                        <p class="mt-1 text-xs leading-5 text-(--lf-text-muted)">Montos y vencimientos</p>
                                    </div>
                                    <div class="rounded-xl border border-(--lf-border) bg-(--lf-bg) p-4">
                                        <span aria-hidden="true" class="text-xl text-(--lf-green)">✓</span>
                                        <p class="mt-3 text-sm font-semibold">Mis pagos</p>
                                        <p class="mt-1 text-xs leading-5 text-(--lf-text-muted)">Historial registrado</p>
                                    </div>
                                </div>
                                <div class="rounded-xl border border-(--lf-border) p-5">
                                    <p class="text-sm font-semibold text-(--lf-blue)">Mi estado de cuenta</p>
                                    <ul class="mt-4 flex flex-col gap-3 text-sm text-(--lf-text-muted)">
                                        <li class="flex items-center justify-between gap-3"><span>Cobros y pagos</span><span aria-hidden="true" class="text-(--lf-green)">✓</span></li>
                                        <li class="flex items-center justify-between gap-3"><span>Saldo pendiente</span><span aria-hidden="true" class="text-(--lf-green)">✓</span></li>
                                        <li class="flex items-center justify-between gap-3"><span>Saldo a favor</span><span aria-hidden="true" class="text-(--lf-green)">✓</span></li>
                                    </ul>
                                </div>
                                <p class="border-l-2 border-(--lf-green) pl-4 text-sm leading-6 text-(--lf-text-muted)">Más claridad para ti.<br><span class="font-semibold text-(--lf-green)">Más tranquilidad para tu comunidad.</span></p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="beneficios" aria-labelledby="beneficios-titulo" class="border-y border-(--lf-border) bg-white">
                <div class="mx-auto max-w-7xl px-6 py-16 lg:px-10 lg:py-20">
                    <div class="max-w-2xl">
                        <p class="text-xs font-semibold tracking-widest text-(--lf-green)">MENOS DUDAS, MÁS INFORMACIÓN</p>
                        <h2 id="beneficios-titulo" class="mt-4 text-3xl leading-tight font-bold tracking-tight text-(--lf-blue) sm:text-4xl">Lo importante de tu comunidad,<br class="hidden sm:block"> a tu alcance.</h2>
                        <p class="mt-5 leading-7 text-(--lf-text-muted)">Un espacio de consulta para entender tus movimientos y conocer el detalle de tu participación.</p>
                    </div>
                    <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            ['01', 'Cobros claros', 'Consulta los montos, vencimientos y estados de tus cobros para identificar lo que está pendiente.'],
                            ['02', 'Pagos con detalle', 'Revisa los pagos registrados por la administración, su aplicación a cobros y los saldos a favor.'],
                            ['03', 'Tu estado de cuenta', 'Consulta el resumen de tus cobros y pagos, y filtra tus movimientos por fechas.'],
                            ['04', 'Tu participación', 'Conoce tus lotes o acciones y su información, según la modalidad de tu comunidad.'],
                        ] as [$numero, $titulo, $descripcion])
                            <article class="rounded-2xl border border-(--lf-border) bg-(--lf-bg) p-6">
                                <span aria-hidden="true" class="text-sm font-semibold text-(--lf-green)">{{ $numero }} /</span>
                                <h3 class="mt-6 text-lg font-semibold text-(--lf-blue)">{{ $titulo }}</h3>
                                <p class="mt-3 text-sm leading-7 text-(--lf-text-muted)">{{ $descripcion }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            <section aria-labelledby="acceso-titulo" class="mx-auto max-w-7xl px-6 py-16 lg:px-10 lg:py-20">
                <div class="grid gap-10 rounded-3xl bg-(--lf-blue) p-8 text-white sm:p-12 lg:grid-cols-[1.2fr_1fr] lg:items-center">
                    <div>
                        <p class="text-xs font-semibold tracking-widest text-green-200">CADA PERFIL, SU ESPACIO</p>
                        <h2 id="acceso-titulo" class="mt-4 text-3xl leading-tight font-bold tracking-tight">Entra y conoce<br>tu información.</h2>
                        <p class="mt-5 max-w-lg text-sm leading-7 text-slate-200">El portal de propietarios es un espacio de consulta personal. La administración gestiona los registros desde su propio acceso.</p>
                    </div>
                    <div class="flex flex-col gap-4">
                        <a href="{{ route('filament.owner.auth.login') }}" class="flex items-center justify-between gap-4 rounded-xl bg-white px-6 py-5 font-semibold text-(--lf-blue) hover:bg-green-50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                            Soy propietario <span aria-hidden="true">→</span>
                        </a>
                        <a href="{{ route('filament.admin.auth.login') }}" class="flex items-center justify-between gap-4 rounded-xl border border-white/40 px-6 py-5 font-semibold hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                            Soy administrador <span aria-hidden="true">→</span>
                        </a>
                        <p class="text-xs leading-6 text-slate-200">¿No tienes acceso? Solicita tu cuenta a la administración de tu comunidad.</p>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-(--lf-border) bg-white">
            <div class="mx-auto flex max-w-7xl flex-col justify-between gap-4 px-6 py-7 text-xs leading-6 text-(--lf-text-muted) sm:flex-row sm:items-center lg:px-10">
                <p><span class="font-semibold text-(--lf-blue)">Loteo Fácil</span> · Información clara para tu comunidad.</p>
                <a href="{{ route('filament.admin.auth.login') }}" class="self-start rounded-sm underline underline-offset-4 hover:text-(--lf-blue) focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-(--lf-focus)">Acceso administración</a>
            </div>
        </footer>
    </body>
</html>

{{-- Badge amarillo con "!" sobre el avatar del user menu cuando el perfil
    del propietario está incompleto. Se inyecta vía PanelsRenderHook::USER_MENU_BEFORE
    en el panel Owner. El script es idempotente: agrega o retira el badge según
    el estado del perfil, para que desaparezca al completarlo. --}}
@php
    $user = auth()->user();
    $showProfileAlert = $user !== null
        && $user->hasRole('propietario')
        && $user->propietario !== null
        && ! $user->propietario->isProfileComplete();
@endphp

<style>
    .fi-user-menu-trigger {
        position: relative;
    }

    .owner-profile-alert-badge {
        position: absolute;
        top: -3px;
        right: -3px;
        z-index: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 0.9rem;
        height: 0.9rem;
        border-radius: 9999px;
        background-color: var(--lf-warning);
        color: var(--lf-white);
        font-size: 0.65rem;
        font-weight: 700;
        line-height: 1;
        pointer-events: none;
    }
</style>

<script>
    (function () {
        var apply = function () {
            document.querySelectorAll('.fi-user-menu-trigger').forEach(function (trigger) {
                var badge = trigger.querySelector('.owner-profile-alert-badge');

                @if ($showProfileAlert)
                    if (! badge) {
                        badge = document.createElement('span');
                        badge.className = 'owner-profile-alert-badge';
                        badge.setAttribute('aria-hidden', 'true');
                        badge.textContent = '!';
                        trigger.appendChild(badge);
                    }
                @else
                    if (badge) {
                        badge.remove();
                    }
                @endif
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', apply, { once: true });
        } else {
            apply();
        }

        window.addEventListener('livewire:navigated', apply);
    })();
</script>

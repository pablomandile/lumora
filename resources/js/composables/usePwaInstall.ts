import { computed, onMounted, onUnmounted, ref } from 'vue';

const STANDALONE = '(display-mode: standalone)';

// iOS/iPadOS Safari nunca dispara beforeinstallprompt: se instala a mano.
function detectIosSafari(): boolean {
    const ua = navigator.userAgent;
    const iosDevice =
        /iPad|iPhone|iPod/.test(ua) ||
        // iPadOS se presenta como Mac; se distingue por el touch.
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    // Chrome/Firefox/Edge en iOS no pueden instalar PWAs.
    return iosDevice && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
}

function detectInstalled(): boolean {
    return (
        window.matchMedia?.(STANDALONE).matches === true ||
        window.navigator.standalone === true ||
        window.__pwaInstall?.installed === true
    );
}

export function usePwaInstall() {
    const isInstalled = ref(detectInstalled());
    const isIos = detectIosSafari();

    // Queda en true aunque el prompt ya se haya usado: así el botón sigue y
    // muestra los pasos a mano en vez de desaparecer.
    const offered = ref(!!window.__pwaInstall?.prompt);

    const canInstall = computed(
        () => !isInstalled.value && (offered.value || isIos),
    );

    const onInstallable = () => (offered.value = true);
    const onInstalled = () => (isInstalled.value = true);
    const onDisplayMode = (event: MediaQueryListEvent) =>
        (isInstalled.value = event.matches);
    const mq = window.matchMedia?.(STANDALONE);

    onMounted(() => {
        window.addEventListener('pwa:installable', onInstallable);
        window.addEventListener('pwa:installed', onInstalled);
        mq?.addEventListener?.('change', onDisplayMode);
    });

    onUnmounted(() => {
        window.removeEventListener('pwa:installable', onInstallable);
        window.removeEventListener('pwa:installed', onInstalled);
        mq?.removeEventListener?.('change', onDisplayMode);
    });

    async function install(): Promise<'accepted' | 'dismissed' | 'manual'> {
        const deferred = window.__pwaInstall?.prompt;

        if (!deferred || !window.__pwaInstall) {
            return 'manual';
        }

        // El evento sirve una sola vez.
        window.__pwaInstall.prompt = null;
        await deferred.prompt();
        const { outcome } = await deferred.userChoice;

        return outcome;
    }

    return { canInstall, isInstalled, isIos, install };
}

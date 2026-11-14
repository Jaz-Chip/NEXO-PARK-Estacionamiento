// Control mínimo de la barra lateral. La lógica de los módulos permanece en su JS propio.
function iniciarMenuLateral() {
    const toggle = document.querySelector('[data-shell-toggle]');
    const backdrop = document.querySelector('[data-shell-close]');
    if (!toggle || !backdrop) return;
    const mq = window.matchMedia('(max-width: 860px)');
    const close = () => {
        document.body.classList.remove('shell-menu-open');
        toggle.setAttribute('aria-expanded', 'false');
    };
    toggle.addEventListener('click', () => {
        const opening = !document.body.classList.contains('shell-menu-open');
        document.body.classList.toggle('shell-menu-open', opening);
        toggle.setAttribute('aria-expanded', String(opening));
    });
    backdrop.addEventListener('click', close);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    mq.addEventListener('change', close);
    // Cierra el cajón móvil después de elegir una opción.
    document.querySelectorAll('#permission-navigation a').forEach(link => {
        link.addEventListener('click', () => { if (mq.matches) close(); });
    });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciarMenuLateral);
else iniciarMenuLateral();

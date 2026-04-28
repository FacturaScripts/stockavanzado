(() => {
    const rules = [
        ['rebuild-movements', 'fa-solid fa-repeat fa-fw'],
        ['rebuild-stock', 'fa-solid fa-dolly fa-fw']
    ];
    function patchButtons() {
        rules.forEach(([action, iconClass]) => {
            document
                .querySelectorAll(`#formListMovimientoStock button[onclick*="${action}"]`)
                .forEach((btn) => {
                    const text = btn.getAttribute('title');
                    if (!text) {
                        return;
                    }

                    btn.innerHTML = `<i class="${iconClass}"></i> <span class="text-nowrap">${text}</span>`;
                });
        });
    }

    document.addEventListener('DOMContentLoaded', patchButtons);
    document.addEventListener('shown.bs.tab', patchButtons);
})();
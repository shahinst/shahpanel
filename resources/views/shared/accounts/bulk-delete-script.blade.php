<script>
(function () {
    const form = document.querySelector('[data-bulk-delete-form]');
    const bar = document.querySelector('[data-bulk-delete-bar]');

    if (!form || !bar) {
        return;
    }

    const counter = form.querySelector('[data-bulk-delete-count]');
    const boxes = () => Array.from(document.querySelectorAll('[data-account-select]'));
    const selected = () => boxes().filter((box) => box.checked);

    function refresh() {
        const count = selected().length;
        counter.textContent = count;
        bar.hidden = count === 0;
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-account-select-all]')) {
            boxes().forEach((box) => { box.checked = event.target.checked; });
        }

        if (event.target.matches('[data-account-select], [data-account-select-all]')) {
            refresh();
        }
    });

    // The ids are gathered at submit time rather than kept in sync with every
    // click, so a stale hidden field can never send an account the admin
    // unticked. Leftovers from a cancelled confirm are cleared first.
    // The bulk renew/enable/disable forms collect the ticked ids the same way.
    [form, ...document.querySelectorAll('[data-bulk-ids-form]')].forEach(function (target) {
        target.addEventListener('submit', function () {
            target.querySelectorAll('input[name="ids[]"]').forEach((input) => input.remove());

            selected().forEach(function (box) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'ids[]';
                hidden.value = box.value;
                target.appendChild(hidden);
            });
        });
    });

    refresh();
})();
</script>

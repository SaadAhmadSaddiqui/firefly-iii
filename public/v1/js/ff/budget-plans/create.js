(function () {
    'use strict';

    function createDynamicList(containerId, addBtnId, templateFn) {
        var container = document.getElementById(containerId);
        var addBtn = document.getElementById(addBtnId);
        if (!container || !addBtn) return;

        var index = 0;

        addBtn.addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'dynamic-row';
            row.innerHTML = templateFn(index);
            container.appendChild(row);
            index++;
        });

        container.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-remove');
            if (btn) {
                btn.closest('.dynamic-row').remove();
            }
        });
    }

    createDynamicList('extra-expenses-container', 'add-expense-btn', function (i) {
        return '<input type="text" class="form-control" name="extra_expenses[' + i + '][name]" placeholder="Expense name">' +
            '<input type="number" step="0.01" min="0" class="form-control" name="extra_expenses[' + i + '][amount]" placeholder="Amount">' +
            '<button type="button" class="btn btn-outline-danger btn-sm btn-remove" aria-label="Remove"><span class="bi bi-x-lg"></span></button>';
    });

    createDynamicList('already-paid-container', 'add-paid-btn', function (i) {
        return '<input type="text" class="form-control" name="already_paid[' + i + '][name]" placeholder="e.g. Spotify">' +
            '<input type="number" step="0.01" min="0" class="form-control" name="already_paid[' + i + '][amount]" placeholder="Amount">' +
            '<input type="text" class="form-control" name="already_paid[' + i + '][account]" placeholder="From account" style="max-width:140px">' +
            '<button type="button" class="btn btn-outline-danger btn-sm btn-remove" aria-label="Remove"><span class="bi bi-x-lg"></span></button>';
    });

    createDynamicList('sub-changes-container', 'add-sub-change-btn', function (i) {
        return '<input type="text" class="form-control" name="subscription_changes[' + i + '][name]" placeholder="Subscription name">' +
            '<select class="form-select" name="subscription_changes[' + i + '][action]" style="max-width:130px">' +
            '<option value="cancelled">Cancelled</option>' +
            '<option value="paused">Paused</option>' +
            '<option value="new">New</option>' +
            '<option value="price_change">Price Change</option>' +
            '</select>' +
            '<input type="number" step="0.01" min="0" class="form-control" name="subscription_changes[' + i + '][amount]" placeholder="Amount (new/changed)" style="max-width:120px">' +
            '<button type="button" class="btn btn-outline-danger btn-sm btn-remove" aria-label="Remove"><span class="bi bi-x-lg"></span></button>';
    });

    var form = document.getElementById('budget-plan-form');
    var overlay = document.getElementById('generating-overlay');
    if (form && overlay) {
        form.addEventListener('submit', function () {
            overlay.classList.add('active');
        });
    }
})();

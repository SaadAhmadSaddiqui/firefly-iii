/*
 * create-edit.js
 * Copyright (c) 2019 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/** global: triggerCount, actionCount */


$(function () {
    "use strict";
    $(".content-wrapper form input:enabled:visible:first").first().focus().select();
    if (triggerCount > 0) {
        console.log('trigger count is larger than zero, call onAddNewTrigger.');
        onAddNewTrigger();
    }

    if (actionCount > 0) {
        console.log('action count is larger than zero, call onAddNewAction.');
        onAddNewAction();
    }

    if (triggerCount === 0) {
        console.log('trigger count is zero, add trigger.');
        addNewTrigger();
    }
    if (actionCount === 0) {
        console.log('action count is zero, add action.');
        addNewAction();
    }
    makeRuleStrict();
    $('.add_rule_trigger').click(addNewTrigger);
    $('.add_rule_action').click(addNewAction);
    $('#ffInput_strict').change(makeRuleStrict);
    $('.test_rule_triggers').click(testRuleTriggers);
    $('.remove-trigger').unbind('click').click(removeTrigger);
    $('.remove-action').unbind('click').click(removeAction);

    // Collect primary + OR values into JSON before form submit.
    $('form').on('submit', function () {
        serializeTriggerOrValues();
    });
});

function makeRuleStrict() {
    var value = $('#ffInput_strict').is(':checked');
    if(value) {
        // is checked, stop processing triggers is not relevant.
        $('.trigger-stop-processing').prop('checked', false);
        $('.trigger-stop-processing').prop('disabled', true);
        return;
    }
    $('.trigger-stop-processing').prop('disabled', false);
}

/**
 * This method triggers when a new trigger must be added to the form.
 */
function addNewTrigger() {
    "use strict";
    triggerCount++;
    console.log('In addNewTrigger(), count is now ' + triggerCount);
    // disable the button
    $('.add_rule_trigger').attr('disabled', 'disabled');

    // get the HTML for the new trigger
    $.getJSON('json/trigger', {count: triggerCount}).done(function (data) {

        // append it to the other triggers
        $('tbody.rule-trigger-tbody').append(data.html);
        $('.remove-trigger').unbind('click').click(removeTrigger);

        // update all "select trigger type" dropdowns
        // so the accompanying text-box has the correct autocomplete.
        onAddNewTrigger();

        $('.add_rule_trigger').removeAttr('disabled');


    }).fail(function () {
        alert('Cannot get a new trigger.');
        $('.add_rule_trigger').removeAttr('disabled');
    });
    return false;

}

/**
 * Method triggers when a new action must be added to the form..
 */
function addNewAction() {
    "use strict";
    actionCount++;
    console.log('In addNewAction(), count is now ' + actionCount);
    // disable the button
    $('.add_rule_action').attr('disabled', 'disabled');


    $.getJSON('json/action', {count: actionCount}).done(function (data) {
        $('tbody.rule-action-tbody').append(data.html);

        // add action things.
        $('.remove-action').unbind('click').click(removeAction);

        // update all "select trigger type" dropdowns
        // so the accompanying text-box has the correct autocomplete.
        onAddNewAction();

        $('.add_rule_action').removeAttr('disabled');

    }).fail(function () {
        alert('Cannot get a new action.');

        $('.add_rule_action').removeAttr('disabled');
    });
    return false;
}

/**
 * Method fires when a trigger must be removed from the form.
 *
 * @param e
 */
function removeTrigger(e) {
    "use strict";
    var target = $(e.currentTarget);
    if (target.prop("tagName") === "SPAN") {
        target = target.parent();
    }
    // remove grand parent:
    target.parent().parent().remove();

    // if now at zero, immediately add one again:
    if ($('.rule-trigger-tbody tr').length === 0) {
        addNewTrigger();
    }
    return false;
}

/**
 * Method fires when an action must be removed from the form.
 *
 * @param e
 */
function removeAction(e) {
    "use strict";
    var target = $(e.target);
    if (target.prop("tagName") === "SPAN") {
        target = target.parent();
    }
    // remove grand parent:
    target.parent().parent().remove();

    // if now at zero, immediately add one again:
    if ($('.rule-action-tbody tr').length === 0) {
        addNewAction();
    }
    return false;

}

/**
 * Method fires when a new action is added. It will update ALL action value input fields.
 */
function onAddNewAction() {
    "use strict";
    console.log('Now in onAddNewAction()');

    var selectQuery  = 'select[name^="actions["][name$="][type]"]';
    var selectResult = $(selectQuery);

    console.log('Select query is "' + selectQuery + '" and the result length is ' + selectResult.length);

    // update all "select action type" dropdown buttons so they will respond correctly
    selectResult.unbind('change').change(function (e) {
        var target = $(e.target);
        updateActionInput(target)
    });

    // make sure each select thing is triggered at least once.
    $.each($('.rule-action-holder'), function (i, v) {
        var holder = $(v);
        var select = holder.find('select');

        console.log('Trigger updateActionInput() for select ' + select);
        updateActionInput(select);
    });
    makeRuleStrict();
}

/**
 * Method fires when a new trigger is added. It will update ALL trigger value input fields.
 */
function onAddNewTrigger() {
    "use strict";
    console.log('Now in onAddNewTrigger()');

    var selectQuery  = 'select[name^="triggers["][name$="][type]"]';
    var selectResult = $(selectQuery);

    console.log('Select query is "' + selectQuery + '" and the result length is ' + selectResult.length);

    // trigger when user changes the trigger type.
    selectResult.unbind('change').change(function (e) {
        var target = $(e.target);
        updateTriggerInput(target)
    });

    // Bind + OR and remove-OR buttons (unbind first to avoid duplicates).
    $('.add-or-value').unbind('click').click(function () {
        addOrValueRow($(this));
    });
    $('.remove-or-value').unbind('click').click(function () {
        $(this).closest('.or-value-row').remove();
    });

    $.each($('.rule-trigger-holder'), function (i, v) {
        var holder = $(v);
        var select = holder.find('select');
        console.log('Trigger updateTriggerInput() for select ' + select);
        try {
            updateTriggerInput(select);
        } catch (e) {
            console.error('Error initializing trigger input:', e);
        }
    });
    makeRuleStrict();
}

/**
 * Creates a nice auto complete action depending on the type of the select list value thing.
 *
 * @param selectList
 */
function updateActionInput(selectList) {
    console.log('Now in updateActionInput() for a select list, currently with value "' + selectList.val() + '".');
    // the actual row this select list is in:
    var parent      = selectList.parent().parent();
    // the text input we're looking for:
    var inputQuery  = 'input[name^="actions["][name$="][value]"]';
    var inputResult = parent.find(inputQuery);

    console.log('Searching for children in this row with query "' + inputQuery + '" resulted in ' + inputResult.length + ' results.');

    inputResult.removeAttr('disabled');
    switch (selectList.val()) {
        case 'set_category':
            console.log('Select list value is ' + selectList.val() + ', so input needs auto complete.');
            createAutoComplete(inputResult, 'api/v1/autocomplete/categories');
            break;
        case 'clear_category':
        case 'clear_budget':
        case 'append_descr_to_notes':
        case 'append_notes_to_descr':
        case 'switch_accounts':
        case 'move_descr_to_notes':
        case 'move_notes_to_descr':
        case 'clear_notes':
        case 'delete_transaction':
        case 'set_source_to_cash':
        case 'set_destination_to_cash':
        case 'remove_all_tags':
            console.log('Select list value is ' + selectList.val() + ', so input needs to be disabled.');
            inputResult.prop('disabled', true);
            inputResult.typeahead('destroy');
            break;
        case 'set_budget':
            console.log('Select list value is ' + selectList.val() + ', so input needs auto complete.');
            createAutoComplete(inputResult, 'api/v1/autocomplete/budgets');
            break;
        case 'add_tag':
        case 'remove_tag':
            console.log('Select list value is ' + selectList.val() + ', so input needs auto complete.');
            createAutoComplete(inputResult, 'api/v1/autocomplete/tags');
            break;
        case 'set_description':
            console.log('Select list value is ' + selectList.val() + ', so input needs auto complete.');
            createAutoComplete(inputResult, 'api/v1/autocomplete/transactions');
            break;
        case 'set_source_account':
        case 'set_destination_account':
            console.log('Select list value is ' + selectList.val() + ', so input needs auto complete.');
            createAutoComplete(inputResult, 'api/v1/autocomplete/accounts');
            break;
        case 'convert_withdrawal':
            console.log('Select list value is ' + selectList.val() + ', so input needs expense accounts auto complete.');
            createAutoComplete(inputResult, 'api/v1/autocomplete/accounts?types=Expense account&');
            break;
        case 'convert_deposit':
            console.log('Select list value is ' + selectList.val() + ', so input needs revenue accounts auto complete.');
            createAutoComplete(inputResult, 'api/v1/autocomplete/accounts?types=Revenue account&');
            break;
        case 'convert_transfer':
            console.log('Select list value is ' + selectList.val() + ', so input needs asset accounts auto complete.');
            createAutoComplete(inputResult, 'api/v1/autocomplete/accounts?types=Asset account&');
            break;
        case 'link_to_bill':
            console.log('Select list value is ' + selectList.val() + ', so input needs auto complete.');
            createAutoComplete(inputResult, 'api/v1/autocomplete/bills');
            break;
        case 'update_piggy':
            console.log('Select list value is ' + selectList.val() + ', so input needs auto complete.');
            createAutoComplete(inputResult, 'api/v1/autocomplete/piggy-banks');
            break;
        default:
            console.log('Select list value is ' + selectList.val() + ', destroy auto complete, do nothing else.');
            inputResult.typeahead('destroy');
            break;
    }
}

/**
 * Creates a nice auto complete trigger depending on the type of the select list value thing.
 *
 * @param selectList
 */
function updateTriggerInput(selectList) {
    console.log('Now in updateTriggerInput() for a select list, currently with value "' + selectList.val() + '".');
    // the actual row this select list is in:
    var parent      = selectList.parent().parent();
    // the text input we're looking for:
    var inputQuery  = 'input[name^="triggers["][name$="][value]"]';
    var inputResult = parent.find(inputQuery);

    console.log('Searching for children in this row with query "' + inputQuery + '" resulted in ' + inputResult.length + ' results.');

    try { inputResult.typeahead('destroy'); } catch (e) { /* ignore */ }
    inputResult.prop('disabled', false);
    inputResult.prop('type', 'text');
    inputResult.removeAttr('placeholder');

    var disableInput = false;
    var autoCompleteURL = null;

    switch (selectList.val()) {
        case 'source_account_starts':
        case 'source_account_ends':
        case 'source_account_is':
        case 'source_account_contains':
        case 'destination_account_starts':
        case 'destination_account_ends':
        case 'destination_account_is':
        case 'destination_account_contains':
            autoCompleteURL = 'api/v1/autocomplete/accounts';
            break;
        case 'tag_is':
            autoCompleteURL = 'api/v1/autocomplete/tags';
            break;
        case 'bill_contains':
        case 'bill_ends':
        case 'bill_is':
        case 'bill_starts':
            autoCompleteURL = 'api/v1/autocomplete/bills';
            break;
        case 'budget_is':
            autoCompleteURL = 'api/v1/autocomplete/budgets';
            break;
        case 'category_is':
            autoCompleteURL = 'api/v1/autocomplete/categories';
            break;
        case 'transaction_type':
            autoCompleteURL = 'api/v1/autocomplete/transaction-types';
            break;
        case 'description_starts':
        case 'description_ends':
        case 'description_contains':
        case 'description_is':
            autoCompleteURL = 'api/v1/autocomplete/transactions';
            break;
        case 'has_no_category':
        case 'no_external_id':
        case 'any_external_id':
        case 'has_any_category':
        case 'has_no_budget':
        case 'has_any_budget':
        case 'has_no_bill':
        case 'has_any_bill':
        case 'has_no_tag':
        case 'no_notes':
        case 'any_notes':
        case 'exists':
        case 'reconciled':
        case 'has_any_tag':
        case 'has_attachments':
        case 'source_is_cash':
        case 'has_no_attachments':
        case 'destination_is_cash':
        case 'account_is_cash':
        case 'no_external_url':
        case 'any_external_url':
            disableInput = true;
            break;
        case 'currency_is':
        case 'foreign_currency_is':
            autoCompleteURL = 'api/v1/autocomplete/currencies-with-code';
            break;
        case 'amount_less':
        case 'amount_more':
        case 'amount_exactly':
            inputResult.attr('placeholder', 'e.g. 27.00');
            break;
        case 'date_on_day':
        case 'interest_date_on_day':
        case 'book_date_on_day':
        case 'process_date_on_day':
        case 'due_date_on_day':
        case 'payment_date_on_day':
        case 'invoice_date_on_day':
        case 'created_at_on_day':
        case 'updated_at_on_day':
            inputResult.attr('placeholder', '1-31');
            break;
        case 'date_on_month':
        case 'interest_date_on_month':
        case 'book_date_on_month':
        case 'process_date_on_month':
        case 'due_date_on_month':
        case 'payment_date_on_month':
        case 'invoice_date_on_month':
        case 'created_at_on_month':
        case 'updated_at_on_month':
            inputResult.attr('placeholder', '1-12');
            break;
        case 'date_on_day_of_week':
        case 'interest_date_on_day_of_week':
        case 'book_date_on_day_of_week':
        case 'process_date_on_day_of_week':
        case 'due_date_on_day_of_week':
        case 'payment_date_on_day_of_week':
        case 'invoice_date_on_day_of_week':
        case 'created_at_on_day_of_week':
        case 'updated_at_on_day_of_week':
            inputResult.attr('placeholder', '1=Mon, 7=Sun');
            break;
        default:
            break;
    }

    if (disableInput) {
        inputResult.prop('disabled', true);
        // Also hide the + OR link for disabled triggers.
        parent.find('.add-or-value').hide();
        parent.find('.or-values-container').hide();
        return;
    }

    parent.find('.add-or-value').show();
    parent.find('.or-values-container').show();

    if (autoCompleteURL) {
        createAutoComplete(inputResult, autoCompleteURL);
        // Also set up autocomplete on any existing OR value inputs.
        parent.find('.or-value-input').each(function () {
            createAutoComplete($(this), autoCompleteURL);
        });
    } else {
        try { inputResult.typeahead('destroy'); } catch (e) { /* ignore */ }
    }

    // Store the autocomplete URL on the row so new OR inputs can pick it up.
    parent.data('autocomplete-url', autoCompleteURL || '');
}

/**
 * Add a new OR-value input row below the trigger value.
 */
function addOrValueRow($link) {
    var $td = $link.closest('td');
    var $container = $td.find('.or-values-container');
    var $row = $('<div class="or-value-row" style="display:flex; align-items:center; margin-top:4px; gap:4px;">' +
        '<span style="font-size:11px; color:#888; flex-shrink:0;">OR</span>' +
        '<input type="text" class="form-control input-sm or-value-input" autocomplete="off" />' +
        '<button type="button" class="btn btn-xs btn-danger remove-or-value" title="Remove">&times;</button>' +
        '</div>');
    $container.append($row);

    var autoCompleteURL = $td.closest('tr').data('autocomplete-url');
    if (autoCompleteURL) {
        createAutoComplete($row.find('.or-value-input'), autoCompleteURL);
    }
}

/**
 * Before form submit / test: gather primary + OR values into JSON on the
 * hidden primary input so the backend receives a single JSON array.
 */
function serializeTriggerOrValues() {
    $('.rule-trigger-holder').each(function () {
        var $row = $(this);
        var $primary = $row.find('.trigger-value-input');
        if (!$primary.length) return;

        var values = [];
        var pv = $.trim($primary.val());
        if (pv !== '') values.push(pv);

        $row.find('.or-value-input').each(function () {
            var v = $.trim($(this).val());
            if (v !== '') values.push(v);
        });

        if (values.length > 1) {
            $primary.val(JSON.stringify(values));
        } else if (values.length === 1) {
            $primary.val(values[0]);
        }
    });
}

/**
 * Create actual autocomplete
 * @param input
 * @param URL
 */
function createAutoComplete(input, URL) {
    console.log('Now in createAutoComplete("' + URL + '").');
    try { input.typeahead('destroy'); } catch (e) { /* ignore */ }

    // append URL:
    var lastChar      = URL[URL.length - 1];
    var urlParamSplit = '?';
    if ('&' === lastChar) {
        urlParamSplit = '';
    }
    var source = new Bloodhound({
                                    datumTokenizer: Bloodhound.tokenizers.obj.whitespace('name'),
                                    queryTokenizer: Bloodhound.tokenizers.whitespace,
                                    prefetch: {
                                        url: URL + urlParamSplit + 'uid=' + uid,
                                        filter: function (list) {
                                            return $.map(list, function (item) {
                                                if (item.hasOwnProperty('active') && item.active === true) {
                                                    return {name: item.name};
                                                }
                                                if (item.hasOwnProperty('active') && item.active === false) {
                                                    return;
                                                }
                                                if (item.hasOwnProperty('active')) {
                                                    console.log(item.active);
                                                }
                                                return {name: item.name};
                                            });
                                        }
                                    },
                                    remote: {
                                        url: URL + urlParamSplit + 'query=%QUERY&uid=' + uid,
                                        wildcard: '%QUERY',
                                        filter: function (list) {
                                            return $.map(list, function (item) {
                                                if (item.hasOwnProperty('active') && item.active === true) {
                                                    return {name: item.name};
                                                }
                                                if (item.hasOwnProperty('active') && item.active === false) {
                                                    return;
                                                }
                                                if (item.hasOwnProperty('active')) {
                                                    console.log(item.active);
                                                }
                                                return {name: item.name};
                                            });
                                        }
                                    }
                                });
    source.initialize();
    input.typeahead({hint: true, highlight: true,}, {source: source, displayKey: 'name', autoSelect: false});
}

function testRuleTriggers() {
    "use strict";

    // find the button:
    var button = $('.test_rule_triggers');

    // replace with spinner. fa-spin fa-spinner
    button.html('<span class="fa fa-spin fa-spinner"></span> ' + testRuleTriggersText);
    button.attr('disabled', 'disabled');

    // Save original values, serialize for submission, then restore UI values.
    var savedValues = [];
    $('.rule-trigger-holder .trigger-value-input').each(function () {
        savedValues.push($(this).val());
    });
    serializeTriggerOrValues();
    var triggerData = $('.content').find("#ffInput_strict, .rule-trigger-tbody input[type=text], .rule-trigger-tbody input[type=number], .rule-trigger-tbody input[type=checkbox], .rule-trigger-tbody select").serializeArray();
    $('.rule-trigger-holder .trigger-value-input').each(function (i) {
        if (i < savedValues.length) $(this).val(savedValues[i]);
    });

    console.log('Found the following trigger data: ');
    console.log(triggerData);

    // Find a list of existing transactions that match these triggers
    $.get('rules/test', triggerData).done(function (data) {
        var modal = $("#testTriggerModal");

        // Set title and body
        modal.find(".transactions-list").html(data.html);
        button.attr('disabled', '');
        // Show warning if appropriate
        if (data.warning) {
            modal.find(".transaction-warning .warning-contents").text(data.warning);
            modal.find(".transaction-warning").show();
        } else {
            modal.find(".transaction-warning").hide();
        }
        button.removeAttr('disabled');
        button.html('<span class="fa fa-flask"></span> ' + testRuleTriggersText);
        // Show the modal dialog
        modal.modal();
    }).fail(function () {
        alert('Cannot get transactions for given triggers.');
    });
    return false;
}

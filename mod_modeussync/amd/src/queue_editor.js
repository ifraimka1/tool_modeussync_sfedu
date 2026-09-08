// This file is part of Moodle - http://moodle.org/

import $ from 'jquery';
import ModalFactory from 'core/modal_factory';
import ModalEvents from 'core/modal_events';
import {getStrings} from 'core/str';

const SELECTORS = {
    form: '[data-region="modeussync-queue-form"]',
    item: '[data-region="queue-item"]',
    module: '[data-region="target-module"]',
    name: '[data-region="name-override"]',
    create: '[data-action="create"]',
    confirmation: '[data-region="confirm-recreate"]',
};

const isChanged = element => element.value !== element.dataset.originalValue;

const changedTypeRows = form => form.find(SELECTORS.item).filter((index, row) => {
    const module = row.querySelector(SELECTORS.module);
    return row.dataset.created === '1' && isChanged(module);
});

const updateCreateButton = form => {
    const button = form.find(SELECTORS.create);
    const changed = form.find(`${SELECTORS.module}, ${SELECTORS.name}`).toArray().some(isChanged);
    button.prop('disabled', !changed && button.data('initial-disabled') === 1);
};

const modalBody = (rows, strings) => {
    const container = $('<div>');
    container.append($('<p>').text(strings[1]));
    const list = $('<ul>');
    rows.each((index, row) => list.append($('<li>').text(row.dataset.itemName)));
    container.append(list);
    if (rows.toArray().some(row => row.dataset.hasGrades === '1')) {
        container.append($('<p class="text-danger">').text(strings[3]));
    }
    return container.html();
};

const confirmRecreation = (form, rows) => getStrings([
    {key: 'recreationconfirmationtitle', component: 'mod_modeussync'},
    {key: 'recreationconfirmationmessage', component: 'mod_modeussync'},
    {key: 'recreationconfirmationbutton', component: 'mod_modeussync'},
    {key: 'recreationgradedwarning', component: 'mod_modeussync'},
]).then(strings => ModalFactory.create({
    type: ModalFactory.types.SAVE_CANCEL,
    title: strings[0],
    body: modalBody(rows, strings),
}).then(modal => {
        const hasGrades = rows.toArray().some(row => row.dataset.hasGrades === '1');
        modal.setSaveButtonText(strings[2]);
        modal.getRoot().find('[data-action="save"]').prop('disabled', hasGrades);
        modal.getRoot().on(ModalEvents.save, event => {
            event.preventDefault();
            form.find(SELECTORS.confirmation).val('1');
            form.append($('<input>', {type: 'hidden', name: 'action', value: 'create'}));
            form[0].submit();
        });
        modal.show();
        return modal;
}));

export const init = () => {
    const form = $(SELECTORS.form);
    if (!form.length) {
        return;
    }
    let requestedAction = null;
    form.find('button[type="submit"]').on('click', event => {
        requestedAction = event.currentTarget.value;
    });
    form.on('change input', `${SELECTORS.module}, ${SELECTORS.name}`, () => updateCreateButton(form));
    form.on('submit', event => {
        const nativeEvent = event.originalEvent;
        const submitter = nativeEvent && nativeEvent.submitter;
        const action = submitter ? submitter.value : (requestedAction || 'create');
        requestedAction = null;
        if (action !== 'create' || form.find(SELECTORS.confirmation).val() === '1') {
            if (!submitter && action) {
                form.append($('<input>', {type: 'hidden', name: 'action', value: action}));
            }
            return;
        }
        const rows = changedTypeRows(form);
        if (!rows.length) {
            if (!submitter) {
                form.append($('<input>', {type: 'hidden', name: 'action', value: action}));
            }
            return;
        }
        event.preventDefault();
        confirmRecreation(form, rows);
    });
    updateCreateButton(form);
};

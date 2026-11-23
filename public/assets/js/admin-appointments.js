(() => {
    'use strict';

    const filters = document.querySelector('[data-appointment-filters]');
    if (filters) {
        const period = filters.elements.namedItem('period');
        const dates = [filters.elements.namedItem('from'), filters.elements.namedItem('to')];
        const updateDates = () => {
            const custom = period.value === 'custom';
            dates.forEach(date => {
                date.disabled = !custom;
                date.required = custom;
            });
        };
        period.addEventListener('change', updateDates);
        updateDates();
    }

    const search = document.querySelector('[data-appointment-participant-search]');
    if (!search) {
        return;
    }

    const input = search.querySelector('input');
    const selector = search.closest('fieldset');
    const participants = selector.querySelectorAll('[data-appointment-participant]');
    const normalize = text => text.trim().toLocaleLowerCase('de');

    search.hidden = false;
    const filterParticipants = () => {
        const query = normalize(input.value);
        participants.forEach(participant => {
            const name = normalize(participant.textContent);
            participant.hidden = !participant.querySelector('input').checked && !name.includes(query);
        });
    };
    input.addEventListener('input', filterParticipants);
    selector.addEventListener('change', filterParticipants);
    filterParticipants();
})();

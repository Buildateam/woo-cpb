document.addEventListener('DOMContentLoaded', function() {
    const initializerCheckbox = document.querySelector('input[name="' + cpbwoo_settings_data.option_use_default_initializer + '"]');
    const scriptUrlRow = document.querySelector('tr.script-url-row');

    function toggleScriptUrlField() {
        if (initializerCheckbox && scriptUrlRow) {
            if (initializerCheckbox.checked) {
                scriptUrlRow.style.display = '';
            } else {
                scriptUrlRow.style.display = 'none';
            }
        }
    }

    // Initial state
    toggleScriptUrlField();

    // Listen for changes
    if (initializerCheckbox) {
        initializerCheckbox.addEventListener('change', toggleScriptUrlField);
    }
});


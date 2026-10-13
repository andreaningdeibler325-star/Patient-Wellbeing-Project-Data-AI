'use strict';

const userInput = document.getElementById('userInput');
const submitQuery = document.getElementById('submitQuery');
const message = document.getElementById('message');
const intentDisplay = document.getElementById('intentDisplay');
const intentValue = document.getElementById('intentValue');

const aiResults = document.getElementById('aiResults');
const resultCount = document.getElementById('resultCount');
const resultIntent = document.getElementById('resultIntent');
const resultAge = document.getElementById('resultAge');
const resultFilter = document.getElementById('resultFilter');
const queryParameters = document.getElementById('queryParameters');

const emptyResults = document.getElementById('emptyResults');
const resultsTableWrapper = document.getElementById('resultsTableWrapper');
const resultsCaption = document.getElementById('resultsCaption');
const resultsHead = document.getElementById('resultsHead');
const resultsBody = document.getElementById('resultsBody');

const continuePrompt = document.getElementById('continuePrompt');
const continueYes = document.getElementById('continueYes');
const continueNo = document.getElementById('continueNo');
const sessionComplete = document.getElementById('sessionComplete');

submitQuery.addEventListener('click', processInput);
continueYes.addEventListener('click', startNewPrompt);
continueNo.addEventListener('click', finishSession);

userInput.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        processInput();
    }
});

function setStatus(text, type = 'loading') {
    message.textContent = text;
    message.className = `status status--${type}`;
}

function clearStatus() {
    message.textContent = '';
    message.className = '';
}

function resetResults() {
    intentDisplay.hidden = true;
    aiResults.hidden = true;
    emptyResults.hidden = true;
    resultsTableWrapper.hidden = true;
    continuePrompt.hidden = true;
    sessionComplete.hidden = true;

    resultsHead.replaceChildren();
    resultsBody.replaceChildren();

    queryParameters.hidden = true;
    queryParameters.replaceChildren();
}

function formatIntent(intent) {
    if (!intent) {
        return 'Unknown';
    }

    return String(intent)
        .toLowerCase()
        .split('_')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

function displayValue(value, fallback = '—') {
    if (value === null || value === undefined || value === '') {
        return fallback;
    }

    return String(value);
}

function addCell(row, value, className = '') {
    const cell = document.createElement('td');

    if (className) {
        cell.className = className;
    }

    cell.textContent = displayValue(value);
    row.appendChild(cell);
    return cell;
}

function createHeader(columns) {
    const row = document.createElement('tr');

    columns.forEach(column => {
        const th = document.createElement('th');
        th.scope = 'col';
        th.textContent = column;
        row.appendChild(th);
    });

    resultsHead.replaceChildren(row);
}

function createRiskBadge(value) {
    const badge = document.createElement('span');
    const normalized = String(value || 'Unknown').toLowerCase();

    badge.className = 'risk-badge';

    if (normalized === 'high') {
        badge.classList.add('risk-badge--high');
    } else if (normalized === 'elevated') {
        badge.classList.add('risk-badge--elevated');
    } else if (normalized === 'normal') {
        badge.classList.add('risk-badge--normal');
    } else {
        badge.classList.add('risk-badge--unknown');
    }

    badge.textContent = displayValue(value, 'Unknown');
    return badge;
}

function createIndicatorChips(indicators) {
    const list = document.createElement('ul');
    list.className = 'indicator-chip-list';

    const items = String(indicators || '')
        .split(',')
        .map(item => item.trim())
        .filter(Boolean);

    if (items.length === 0) {
        const item = document.createElement('li');
        item.className = 'indicator-chip indicator-chip--none';
        item.textContent = 'None recorded';
        list.appendChild(item);
        return list;
    }

    items.forEach(indicator => {
        const item = document.createElement('li');
        item.className = 'indicator-chip';
        item.textContent = indicator;
        list.appendChild(item);
    });

    return list;
}

function renderWellbeingFollowup(patients) {
    createHeader([
        'Patient ID',
        'Name',
        'Age',
        'Last Checkup',
        'Checkup Status',
        'Indicators',
        'Indicators Present'
    ]);

    patients.forEach(patient => {
        const row = document.createElement('tr');

        addCell(row, patient.Patient_ID);
        addCell(row, patient.Name);
        addCell(row, patient.Age);
        addCell(row, patient.Last_Checkup_Date);
        addCell(row, patient.Checkup_Status);
        addCell(row, patient.Wellbeing_Indicator_Count);

        const indicatorsCell = document.createElement('td');
        indicatorsCell.appendChild(
            createIndicatorChips(patient.Indicators_Present)
        );
        row.appendChild(indicatorsCell);

        resultsBody.appendChild(row);
    });
}

function renderHighBloodPressure(patients) {
    createHeader([
        'Patient ID',
        'Name',
        'Age',
        'Blood Pressure',
        'BMI',
        'Condition',
        'Last Checkup',
        'Risk Level'
    ]);

    patients.forEach(patient => {
        const row = document.createElement('tr');

        addCell(row, patient.Patient_ID);
        addCell(row, patient.Name);
        addCell(row, patient.Age);

        const systolic = displayValue(
            patient.Blood_Pressure_Systolic,
            '?'
        );
        const diastolic = displayValue(
            patient.Blood_Pressure_Diastolic,
            '?'
        );

        addCell(row, `${systolic}/${diastolic}`);
        addCell(row, patient.BMI);
        addCell(row, patient.Condition);
        addCell(row, patient.Last_Checkup_Date);

        const riskCell = document.createElement('td');
        riskCell.appendChild(
            createRiskBadge(patient.BloodPressureRiskLevel)
        );
        row.appendChild(riskCell);

        resultsBody.appendChild(row);
    });
}

function renderFallback(patients) {
    createHeader(['Patient ID', 'Name', 'Age']);

    patients.forEach(patient => {
        const row = document.createElement('tr');
        addCell(row, patient.Patient_ID);
        addCell(row, patient.Name);
        addCell(row, patient.Age);
        resultsBody.appendChild(row);
    });
}

function renderQueryParameters(result) {
    const parameters = result.data?.parameters || result.parameters || {};

    queryParameters.replaceChildren();

    const strong = document.createElement('strong');
    strong.textContent = 'Applied parameters: ';
    queryParameters.appendChild(strong);

    let text = '';

    if (result.intent === 'WELLBEING_FOLLOWUP') {
        text =
            `minimum age ${displayValue(parameters.minimumAge)}, ` +
            `at least ${displayValue(parameters.minimumIndicators)} indicators, ` +
            `checkup more than ${displayValue(parameters.monthsSinceCheckup)} months ago`;
    } else if (result.intent === 'HIGH_BLOOD_PRESSURE') {
        text =
            `minimum age ${displayValue(parameters.minimumAge)}, ` +
            `blood pressure threshold ${displayValue(parameters.minimumSystolic)}/` +
            `${displayValue(parameters.minimumDiastolic)}, ` +
            `high threshold ${displayValue(parameters.highSystolic)}/` +
            `${displayValue(parameters.highDiastolic)}`;
    } else {
        text = 'Parameters were interpreted by the AI inquiry service.';
    }

    queryParameters.appendChild(document.createTextNode(text));
    queryParameters.hidden = false;
}

function updateSummary(result, patients) {
    const parameters = result.data?.parameters || result.parameters || {};

    resultCount.textContent =
        displayValue(result.data?.count ?? patients.length, '0');

    resultIntent.textContent = formatIntent(result.intent);
    resultAge.textContent = displayValue(parameters.minimumAge);

    if (result.intent === 'WELLBEING_FOLLOWUP') {
        resultFilter.textContent =
            `${displayValue(parameters.minimumIndicators)}+ indicators / ` +
            `${displayValue(parameters.monthsSinceCheckup)} months`;
    } else if (result.intent === 'HIGH_BLOOD_PRESSURE') {
        resultFilter.textContent =
            `${displayValue(parameters.minimumSystolic)}/` +
            `${displayValue(parameters.minimumDiastolic)}+ BP`;
    } else {
        resultFilter.textContent = 'AI-selected';
    }
}

function renderResults(result) {
    const patients = Array.isArray(result.data?.patients)
        ? result.data.patients
        : [];

    resultsHead.replaceChildren();
    resultsBody.replaceChildren();

    updateSummary(result, patients);
    renderQueryParameters(result);

    intentValue.textContent = result.intent || 'Unknown';
    intentDisplay.hidden = false;
    aiResults.hidden = false;

    if (patients.length === 0) {
        emptyResults.hidden = false;
        resultsTableWrapper.hidden = true;
        continuePrompt.hidden = false;
        return;
    }

    emptyResults.hidden = true;

    if (result.intent === 'WELLBEING_FOLLOWUP') {
        resultsCaption.textContent =
            'Patients matching the wellbeing follow-up inquiry';
        renderWellbeingFollowup(patients);
    } else if (result.intent === 'HIGH_BLOOD_PRESSURE') {
        resultsCaption.textContent =
            'Patients matching the high blood pressure inquiry';
        renderHighBloodPressure(patients);
    } else {
        resultsCaption.textContent = 'Patients matching the inquiry';
        renderFallback(patients);
    }

    resultsTableWrapper.hidden = false;
    continuePrompt.hidden = false;
}


function startNewPrompt() {
    clearStatus();

    intentDisplay.hidden = true;
    aiResults.hidden = true;
    continuePrompt.hidden = true;
    sessionComplete.hidden = true;

    userInput.value = '';
    userInput.disabled = false;
    submitQuery.disabled = false;

    userInput.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
    });

    userInput.focus();
}

function finishSession() {
    continuePrompt.hidden = true;
    sessionComplete.hidden = false;

    userInput.disabled = true;
    submitQuery.disabled = true;
}

async function processInput() {
    const userQuery = userInput.value.trim();

    if (!userQuery) {
        resetResults();
        setStatus('Please enter a question.', 'error');
        userInput.focus();
        return;
    }

    submitQuery.disabled = true;
    sessionComplete.hidden = true;
    resetResults();
    setStatus('Processing your request...', 'loading');

    try {
        const response = await fetch(
            'http://localhost:3001/api/query',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    query: userQuery
                })
            }
        );

        const result = await response.json();

        if (!response.ok) {
            throw new Error(
                result.error || 'The request failed.'
            );
        }

        console.log('Server response:', result);

        setStatus(
            'Your inquiry was interpreted successfully.',
            'loading'
        );

        renderResults(result);

    } catch (error) {
        console.error('Dashboard error:', error);

        resetResults();

        setStatus(
            'Error: ' +
            (error instanceof Error
                ? error.message
                : 'Unknown error'),
            'error'
        );
    } finally {
        submitQuery.disabled = false;
    }
}
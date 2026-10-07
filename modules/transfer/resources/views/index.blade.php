@extends('layouts.panel')

@section('page_title', __('transfer::transfer.page_title'))

@section('panel_content')
<p class="text-muted">{{ __('transfer::transfer.intro') }}</p>

<x-alert type="warning" class="margin-bottom">{{ __('transfer::transfer.warning') }}</x-alert>

<div class="panel-modern-card mb-3">
    <div class="card-head"><h3>{{ __('transfer::transfer.page_title') }}</h3></div>
    <div class="card-body">
        <form id="transfer-form" class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="transfer-file">{{ __('transfer::transfer.file_label') }}</label>
                <input type="file" id="transfer-file" class="form-control" accept=".zip,.gz,.sql" required>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="transfer-key">{{ __('transfer::transfer.key_label') }}</label>
                <input type="text" id="transfer-key" class="form-control" dir="ltr" autocomplete="off" spellcheck="false" placeholder="base64:…">
                <p class="text-muted small mb-0 mt-1">{{ __('transfer::transfer.key_hint') }}</p>
            </div>
            <div class="col-12">
                <label class="form-check">
                    <input type="checkbox" id="transfer-confirm" class="form-check-input" required>
                    <span class="form-check-label">{{ __('transfer::transfer.confirm') }}</span>
                </label>
            </div>
            <div class="col-12">
                <x-button type="submit" id="transfer-start">{{ __('transfer::transfer.start') }}</x-button>
            </div>
        </form>

        <div id="transfer-progress" class="mt-4" hidden>
            <p id="transfer-upload" class="mb-2"></p>
            <ol id="transfer-steps" class="mb-3">
                @foreach ($steps as $step)
                    <li data-step="{{ $step }}" class="text-muted">{{ __('transfer::transfer.steps.'.$step) }}</li>
                @endforeach
            </ol>
            <div id="transfer-result"></div>
        </div>
    </div>
</div>

<script>
(() => {
    const form = document.getElementById('transfer-form');
    const job = @json($job);
    const chunkBytes = @json($chunkBytes);
    const steps = @json($steps);
    const urls = {chunk: @json(route('admin.transfer.chunk')), start: @json(route('admin.transfer.start'))};
    const text = {
        uploading: @json(__('transfer::transfer.uploading')),
        starting: @json(__('transfer::transfer.starting')),
        done: @json(__('transfer::transfer.done')),
        login: @json(__('transfer::transfer.login')),
        failed: @json(__('transfer::transfer.failed')),
        outcomes: {
            untouched: @json(__('transfer::transfer.outcome_untouched')),
            rolled_back: @json(__('transfer::transfer.outcome_rolled_back')),
            rollback_failed: @json(__('transfer::transfer.outcome_rollback_failed')),
        },
    };
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const progress = document.getElementById('transfer-progress');
    const upload = document.getElementById('transfer-upload');
    const result = document.getElementById('transfer-result');

    const post = async (url, body) => {
        const response = await fetch(url, {method: 'POST', body, headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json'}});
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.message || response.statusText);
        }
        return data;
    };

    const show = (html, type) => {
        result.innerHTML = '';
        const box = document.createElement('div');
        box.className = 'alert alert-' + type;
        box.innerHTML = html;
        result.appendChild(box);
    };

    const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    const render = (status) => {
        const current = steps.indexOf(status.step);
        document.querySelectorAll('#transfer-steps li').forEach((item, index) => {
            const finished = status.state === 'done' || (current !== -1 && index < current);
            item.className = finished ? 'text-success' : (index === current ? 'fw-bold' : 'text-muted');
        });

        if (status.state === 'done') {
            show(escape(text.done) + ' <a class="btn btn-primary btn-sm ms-2" href="' + escape(status.login_url) + '">' + escape(text.login) + '</a>', 'success');
            return true;
        }

        if (status.state === 'failed') {
            const outcome = (text.outcomes[status.outcome] || '').replace(':file', status.safety_backup || '');
            show(escape(text.failed.replace(':message', status.message || '')) + '<br>' + escape(outcome), 'danger');
            return true;
        }

        return false;
    };

    const poll = (url) => {
        const tick = async () => {
            try {
                const response = await fetch(url, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
                if (response.ok && render(await response.json())) {
                    return;
                }
            } catch (error) {
                // The panel restarts parts of itself while restoring; keep asking.
            }
            setTimeout(tick, 2000);
        };
        tick();
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const file = document.getElementById('transfer-file').files[0];
        if (!file) {
            return;
        }

        form.querySelectorAll('input, button').forEach((element) => element.disabled = true);
        progress.hidden = false;
        result.innerHTML = '';

        try {
            for (let offset = 0; offset < file.size; offset += chunkBytes) {
                upload.textContent = text.uploading.replace(':percent', Math.floor(offset * 100 / file.size));
                const body = new FormData();
                body.append('job', job);
                body.append('offset', offset);
                body.append('chunk', file.slice(offset, offset + chunkBytes), 'chunk');
                await post(urls.chunk, body);
            }

            upload.textContent = text.starting;
            const body = new FormData();
            body.append('job', job);
            body.append('app_key', document.getElementById('transfer-key').value);
            body.append('confirm', '1');
            const started = await post(urls.start, body);
            upload.textContent = text.uploading.replace(':percent', 100);
            poll(started.status_url);
        } catch (error) {
            show(escape(error.message), 'danger');
            form.querySelectorAll('input, button').forEach((element) => element.disabled = false);
        }
    });
})();
</script>
@endsection

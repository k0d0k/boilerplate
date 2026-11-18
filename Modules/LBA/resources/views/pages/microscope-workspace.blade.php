@php
    use Modules\LBA\Filament\Resources\ExaminationResource;
    use Modules\LBA\Models\AnalysisJob;

    $examination = $this->examination();
    $images = $this->images();
    $selected = $this->selectedImage();
    $annotations = $this->annotations();
    $selectedAnnotation = $this->selectedAnnotation();
    $canAnnotate = $this->canAnnotate();
    $canCapture = $this->canCapture();
    $captureLimitKb = $this->captureUploadLimitKb();
    $detections = $this->detections();
    $aiEnabled = $this->aiEnabled();
    $canStartAnalysis = $this->canStartAnalysis();
    $latestJob = $this->latestJob();
    $aiLabelOptions = $this->aiLabelOptions();
    $width = $selected && $selected->width ? (int) $selected->width : 1000;
    $height = $selected && $selected->height ? (int) $selected->height : 1000;
    $pointRadius = max(3, (int) round(min($width, $height) * 0.012));
@endphp

<x-filament-panels::page>
    <style>
        .lba-ws {
            --lba-ws-bg: #0c1725;
            --lba-ws-panel: #0f1d2f;
            --lba-ws-line: rgba(255, 255, 255, 0.10);
            --lba-ws-fg: rgba(255, 255, 255, 0.92);
            --lba-ws-muted: rgba(255, 255, 255, 0.55);
            --lba-ws-teal: #0aa5a6;
            --lba-ws-bright: #7fe3e0;
            background: var(--lba-ws-bg);
            border: 1px solid var(--lba-ws-line);
            border-radius: 0.75rem;
            padding: 1.25rem;
            color: var(--lba-ws-fg);
        }
        .lba-ws__bar { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--lba-ws-line); }
        .lba-ws__eyebrow { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.16em; text-transform: uppercase; color: var(--lba-ws-bright); }
        .lba-ws__title { margin-top: 0.25rem; font-size: 1.25rem; font-weight: 600; color: #fff; }
        .lba-ws__meta { margin-top: 0.375rem; display: flex; flex-wrap: wrap; gap: 0.5rem; font-size: 0.75rem; color: var(--lba-ws-muted); }
        .lba-ws__bar-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .lba-ws__btn { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.5rem 0.875rem; border: 1px solid var(--lba-ws-line); border-radius: 0.625rem; background: rgba(255,255,255,0.04); color: var(--lba-ws-fg); font-size: 0.8125rem; font-weight: 600; text-decoration: none; cursor: pointer; }
        .lba-ws__btn:hover { background: rgba(255,255,255,0.09); }
        .lba-ws__btn--primary { border-color: transparent; background: var(--lba-ws-teal); color: #fff; }
        .lba-ws__btn--primary:hover { background: #0b8f90; }
        .lba-ws__btn--danger { border-color: rgba(225,29,72,0.5); color: #ffb4c6; }
        .lba-ws__btn--danger:hover { background: rgba(225,29,72,0.18); }
        .lba-ws__btn.is-active { border-color: var(--lba-ws-bright); background: rgba(10,165,166,0.28); color: #fff; }

        .lba-ws__grid { display: grid; gap: 1rem; margin-top: 1rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 1100px) { .lba-ws__grid { grid-template-columns: 9.5rem minmax(0, 1fr) 20rem; } }

        .lba-ws__rail { display: flex; flex-direction: column; gap: 0.5rem; }
        .lba-ws__rail-title { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--lba-ws-muted); }
        .lba-ws-thumb { position: relative; display: block; width: 100%; padding: 0; border: 2px solid transparent; border-radius: 0.625rem; background: #0a1320; overflow: hidden; cursor: pointer; }
        .lba-ws-thumb img { display: block; width: 100%; height: 4.25rem; object-fit: cover; }
        .lba-ws-thumb.is-active { border-color: var(--lba-ws-bright); }
        .lba-ws-thumb__count { position: absolute; right: 0.25rem; bottom: 0.25rem; padding: 0.0625rem 0.375rem; border-radius: 999px; background: rgba(0,0,0,0.6); font-size: 0.625rem; color: #fff; }

        .lba-ws__center { display: flex; flex-direction: column; gap: 0.625rem; min-width: 0; }
        .lba-ws__toolbar { display: flex; flex-wrap: wrap; gap: 0.375rem; }
        .lba-ws__stage { position: relative; display: flex; align-items: center; justify-content: center; min-height: 20rem; max-height: 70vh; padding: 0.75rem; border: 1px solid var(--lba-ws-line); border-radius: 0.625rem; background: #08111c; overflow: hidden; }
        .lba-ws__canvas { position: relative; display: inline-block; line-height: 0; transform-origin: center center; }
        .lba-ws__image { display: block; max-width: 100%; max-height: 64vh; width: auto; height: auto; user-select: none; -webkit-user-drag: none; }
        .lba-ws__overlay { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
        .lba-ws__shape { pointer-events: none; }
        .lba-ws__shape--selectable { pointer-events: auto; cursor: pointer; }
        .lba-ws__stage--draw { cursor: crosshair; }
        .lba-ws__stage--pan { cursor: grab; }
        .lba-ws__zoombar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; font-size: 0.75rem; color: var(--lba-ws-muted); }
        .lba-ws__range { width: 8rem; }

        .lba-ws__panel { display: flex; flex-direction: column; gap: 1rem; }
        .lba-ws__block { padding: 0.875rem; border: 1px solid var(--lba-ws-line); border-radius: 0.625rem; background: var(--lba-ws-panel); }
        .lba-ws__block-title { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--lba-ws-muted); margin-bottom: 0.625rem; }
        .lba-ws__dl { display: grid; grid-template-columns: auto 1fr; gap: 0.375rem 0.75rem; font-size: 0.8125rem; }
        .lba-ws__dl dt { color: var(--lba-ws-muted); }
        .lba-ws__dl dd { color: var(--lba-ws-fg); overflow-wrap: anywhere; }
        .lba-ws__field { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 0.625rem; }
        .lba-ws__label { font-size: 0.75rem; font-weight: 600; color: var(--lba-ws-muted); }
        .lba-ws__input, .lba-ws__textarea { width: 100%; padding: 0.5rem 0.625rem; border: 1px solid var(--lba-ws-line); border-radius: 0.5rem; background: #08111c; color: #fff; font-size: 0.8125rem; }
        .lba-ws__textarea { resize: vertical; min-height: 3.5rem; }
        .lba-ws__list { display: flex; flex-direction: column; gap: 0.375rem; margin: 0; padding: 0; list-style: none; max-height: 16rem; overflow-y: auto; }
        .lba-ws__item { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; width: 100%; padding: 0.5rem 0.625rem; border: 1px solid var(--lba-ws-line); border-radius: 0.5rem; background: rgba(255,255,255,0.03); color: var(--lba-ws-fg); font-size: 0.8125rem; text-align: left; cursor: pointer; }
        .lba-ws__item.is-active { border-color: var(--lba-ws-bright); background: rgba(10,165,166,0.18); }
        .lba-ws__item-type { font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--lba-ws-bright); }
        .lba-ws__empty { font-size: 0.8125rem; color: var(--lba-ws-muted); }
        .lba-ws__hint { font-size: 0.6875rem; color: var(--lba-ws-muted); }
        .lba-ws__mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.6875rem; word-break: break-all; }

        /* WS6.A — browser camera capture */
        .lba-ws__capture { margin-bottom: 0.625rem; }
        .lba-ws__capture-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
        .lba-ws__capture-select { max-width: 16rem; }
        .lba-ws__capture-video { display: block; width: 100%; max-height: 20rem; margin-top: 0.625rem; border: 1px solid var(--lba-ws-line); border-radius: 0.5rem; background: #000; object-fit: contain; }
        .lba-ws__capture-error { margin-top: 0.5rem; font-size: 0.75rem; color: #ffb4c6; }
        .lba-ws__capture-progress { margin-top: 0.5rem; font-size: 0.75rem; color: var(--lba-ws-bright); }

        /* AI suggestions — deliberately distinct from human annotations. */
        .lba-ws__shape--ai { stroke-dasharray: 6 4; }
        .lba-ws__badge { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.0625rem 0.4rem; border-radius: 999px; font-size: 0.625rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; }
        .lba-ws__badge--ai { background: rgba(232,121,249,0.20); color: #f0abfc; }
        .lba-ws__badge--human { background: rgba(255,209,102,0.20); color: #ffd166; }
        .lba-ws__badge--muted { background: rgba(255,255,255,0.10); color: var(--lba-ws-muted); }
        .lba-ws__suggestion { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.375rem 0.5rem; border: 1px dashed rgba(232,121,249,0.55); border-radius: 0.5rem; background: rgba(232,121,249,0.08); font-size: 0.75rem; }
        .lba-ws__suggestion-label { color: var(--lba-ws-fg); overflow-wrap: anywhere; }
        .lba-ws__suggestion-conf { color: #f0abfc; font-weight: 600; white-space: nowrap; }
        .lba-ws__checkline { display: flex; align-items: center; gap: 0.5rem; font-size: 0.75rem; color: var(--lba-ws-fg); cursor: pointer; }
        .lba-ws__statusrow { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; font-size: 0.75rem; }
    </style>

    <div
        class="lba-ws"
        x-data="lbaWorkspace({
            imageWidth: {{ $width }},
            imageHeight: {{ $height }},
            canAnnotate: {{ $canAnnotate ? 'true' : 'false' }},
        })"
    >
        <div class="lba-ws__bar">
            <div>
                <p class="lba-ws__eyebrow">{{ __('lba.exams.workspace.eyebrow') }}</p>
                <h2 class="lba-ws__title">{{ $examination->patient?->full_name ?? '—' }}</h2>
                <p class="lba-ws__meta">
                    <span>{{ __('lba.exams.id') }}: {{ $examination->getKey() }}</span>
                    <span>·</span>
                    <span>{{ __('lba.exams.status') }}: {{ __('lba.exams.status_'.$examination->status) }}</span>
                    <span>·</span>
                    <span>{{ __('lba.exams.mode') }}: {{ __('lba.exams.mode_'.$examination->mode) }}</span>
                    @if ($examination->isAiCapable())
                        <span>·</span>
                        <span class="lba-ws__badge lba-ws__badge--ai">{{ __('lba.exams.ai.heading') }}</span>
                    @endif
                </p>
            </div>
            <div class="lba-ws__bar-actions">
                @if ($this->canSubmitForReview())
                    <button type="button" class="lba-ws__btn lba-ws__btn--primary" wire:click="submitForReview">
                        {{ __('lba.exams.action_submit_review') }}
                    </button>
                @endif
                <a class="lba-ws__btn" href="{{ ExaminationResource::getUrl('view', ['record' => $examination]) }}">
                    {{ __('lba.exams.workspace.back') }}
                </a>
            </div>
        </div>

        <div class="lba-ws__grid">
            {{-- LEFT: image thumbnails --}}
            <aside class="lba-ws__rail">
                <p class="lba-ws__rail-title">{{ __('lba.exams.workspace.images') }}</p>
                @forelse ($images as $image)
                    <button
                        type="button"
                        class="lba-ws-thumb {{ $selected && (string) $selected->getKey() === (string) $image->getKey() ? 'is-active' : '' }}"
                        wire:click="selectImage('{{ $image->getKey() }}')"
                        wire:key="thumb-{{ $image->getKey() }}"
                    >
                        <img src="{{ $image->streamUrl() }}" alt="{{ __('lba.exams.workspace.image_alt') }}">
                        <span class="lba-ws-thumb__count">{{ $image->annotations()->count() }}</span>
                    </button>
                @empty
                    <p class="lba-ws__empty">{{ __('lba.exams.workspace.no_images') }}</p>
                @endforelse
            </aside>

            {{-- CENTER: viewer --}}
            <section class="lba-ws__center">
                @if ($canCapture)
                    <div
                        class="lba-ws__block lba-ws__capture"
                        x-data="lbaCapture({
                            limitKb: {{ $captureLimitKb }},
                            msgs: {
                                denied: @js(__('lba.exams.workspace.capture_denied')),
                                notFound: @js(__('lba.exams.workspace.capture_not_found')),
                                generic: @js(__('lba.exams.workspace.capture_error')),
                            },
                        })"
                        x-init="init()"
                        x-on:livewire:navigating.window="stop()"
                    >
                        <p class="lba-ws__block-title">{{ __('lba.exams.workspace.capture_title') }}</p>

                        <template x-if="!supported">
                            <p class="lba-ws__empty">{{ __('lba.exams.workspace.capture_unsupported') }}</p>
                        </template>

                        <template x-if="supported">
                            <div>
                                <div class="lba-ws__capture-row">
                                    <select class="lba-ws__input lba-ws__capture-select" x-model="deviceId" x-on:change="restart()">
                                        <template x-for="device in devices" x-bind:key="device.deviceId">
                                            <option x-bind:value="device.deviceId" x-text="device.label || @js(__('lba.exams.workspace.capture_camera'))"></option>
                                        </template>
                                    </select>
                                    <template x-if="! streaming">
                                        <button type="button" class="lba-ws__btn lba-ws__btn--primary" x-on:click="start()" x-bind:disabled="busy">
                                            {{ __('lba.exams.workspace.capture_start') }}
                                        </button>
                                    </template>
                                    <template x-if="streaming">
                                        <button type="button" class="lba-ws__btn" x-on:click="stop()">
                                            {{ __('lba.exams.workspace.capture_stop') }}
                                        </button>
                                    </template>
                                    <template x-if="streaming">
                                        <button type="button" class="lba-ws__btn lba-ws__btn--primary" x-on:click="capture()" x-bind:disabled="busy">
                                            {{ __('lba.exams.workspace.capture_still') }}
                                        </button>
                                    </template>
                                </div>

                                <video x-ref="video" class="lba-ws__capture-video" x-show="streaming" autoplay playsinline muted></video>
                                <canvas x-ref="canvas" style="display: none;"></canvas>

                                <p class="lba-ws__hint">{{ __('lba.exams.workspace.capture_hint', ['kb' => $captureLimitKb]) }}</p>
                                <p class="lba-ws__capture-error" x-show="error" x-cloak x-text="error"></p>
                                <p class="lba-ws__capture-progress" x-show="uploading" x-cloak>
                                    {{ __('lba.exams.workspace.capture_uploading') }} <span x-text="progress + '%'"></span>
                                </p>
                            </div>
                        </template>
                    </div>
                @endif

                @if ($selected)
                    <div class="lba-ws__toolbar">
                        @foreach (['select' => 'tool_select', 'point' => 'tool_point', 'rectangle' => 'tool_rectangle', 'polygon' => 'tool_polygon'] as $toolKey => $toolLabel)
                            <button
                                type="button"
                                class="lba-ws__btn {{ $tool === $toolKey ? 'is-active' : '' }}"
                                wire:click="setTool('{{ $toolKey }}')"
                                @disabled(! $canAnnotate && $toolKey !== 'select')
                            >
                                {{ __('lba.exams.workspace.'.$toolLabel) }}
                            </button>
                        @endforeach
                        <button type="button" class="lba-ws__btn" x-show="$wire.tool === 'polygon'" x-cloak x-on:click="undoPoint()">
                            {{ __('lba.exams.workspace.undo_point') }}
                        </button>
                        <button type="button" class="lba-ws__btn lba-ws__btn--primary" x-show="$wire.tool === 'polygon'" x-cloak x-on:click="finishPolygon()">
                            {{ __('lba.exams.workspace.finish_polygon') }}
                        </button>
                        @if ($examination->isAiCapable())
                            <button type="button" class="lba-ws__btn" wire:click="toggleAiOverlay">
                                {{ $this->aiOverlayVisible ? __('lba.exams.ai.hide_overlay') : __('lba.exams.ai.show_overlay') }}
                            </button>
                            @if ($canStartAnalysis)
                                <button type="button" class="lba-ws__btn lba-ws__btn--primary" wire:click="startAnalysis" wire:loading.attr="disabled">
                                    {{ __('lba.exams.ai.start') }}
                                </button>
                            @endif
                        @endif
                    </div>

                    <div
                        class="lba-ws__stage {{ $tool === 'select' ? 'lba-ws__stage--pan' : 'lba-ws__stage--draw' }}"
                        x-ref="stage"
                        x-on:pointerdown="pointerDown($event)"
                        x-on:pointermove="pointerMove($event)"
                        x-on:pointerup="pointerUp($event)"
                        x-on:pointerleave="pointerUp($event)"
                        wire:key="viewer-{{ $selected->getKey() }}"
                    >
                        <div class="lba-ws__canvas" x-ref="canvas" x-bind:style="canvasStyle()">
                            <img class="lba-ws__image" x-bind:style="imageStyle()" src="{{ $selected->streamUrl() }}" alt="{{ __('lba.exams.workspace.image_alt') }}" draggable="false">

                            <svg class="lba-ws__overlay" viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none">
                                @foreach ($annotations as $annotation)
                                    @php
                                        $geometry = $annotation->geometry ?? [];
                                        $isSelected = $selectedAnnotation && (string) $selectedAnnotation->getKey() === (string) $annotation->getKey();
                                        $stroke = $isSelected ? '#7fe3e0' : '#ffd166';
                                        $fill = $isSelected ? 'rgba(127,227,224,0.22)' : 'rgba(255,209,102,0.16)';
                                        $selectable = $tool === 'select';
                                    @endphp

                                    @if ($annotation->type === \Modules\LBA\Models\Annotation::TYPE_POINT)
                                        <circle
                                            class="lba-ws__shape {{ $selectable ? 'lba-ws__shape--selectable' : '' }}"
                                            cx="{{ (float) ($geometry['x'] ?? 0) * $width }}"
                                            cy="{{ (float) ($geometry['y'] ?? 0) * $height }}"
                                            r="{{ $pointRadius }}"
                                            fill="{{ $fill }}"
                                            stroke="{{ $stroke }}"
                                            stroke-width="2"
                                            vector-effect="non-scaling-stroke"
                                            wire:click="selectAnnotation('{{ $annotation->getKey() }}')"
                                            onclick="event.stopPropagation()"
                                        />
                                    @elseif ($annotation->type === \Modules\LBA\Models\Annotation::TYPE_RECTANGLE)
                                        <rect
                                            class="lba-ws__shape {{ $selectable ? 'lba-ws__shape--selectable' : '' }}"
                                            x="{{ (float) ($geometry['x'] ?? 0) * $width }}"
                                            y="{{ (float) ($geometry['y'] ?? 0) * $height }}"
                                            width="{{ (float) ($geometry['width'] ?? 0) * $width }}"
                                            height="{{ (float) ($geometry['height'] ?? 0) * $height }}"
                                            fill="{{ $fill }}"
                                            stroke="{{ $stroke }}"
                                            stroke-width="2"
                                            vector-effect="non-scaling-stroke"
                                            wire:click="selectAnnotation('{{ $annotation->getKey() }}')"
                                            onclick="event.stopPropagation()"
                                        />
                                    @elseif ($annotation->type === \Modules\LBA\Models\Annotation::TYPE_POLYGON)
                                        @php
                                            $points = collect($geometry['points'] ?? [])
                                                ->map(fn ($p) => ((float) ($p[0] ?? 0) * $width).','.((float) ($p[1] ?? 0) * $height))
                                                ->implode(' ');
                                        @endphp
                                        <polygon
                                            class="lba-ws__shape {{ $selectable ? 'lba-ws__shape--selectable' : '' }}"
                                            points="{{ $points }}"
                                            fill="{{ $fill }}"
                                            stroke="{{ $stroke }}"
                                            stroke-width="2"
                                            vector-effect="non-scaling-stroke"
                                            wire:click="selectAnnotation('{{ $annotation->getKey() }}')"
                                            onclick="event.stopPropagation()"
                                        />
                                    @endif
                                @endforeach

                                {{-- AI suggestions (unreviewed) — dashed, distinct colour --}}
                                @if ($this->aiOverlayVisible)
                                    @foreach ($detections as $detection)
                                        @php
                                            $geometry = $detection->geometry ?? [];
                                            $aiType = $detection->geometryType();
                                        @endphp

                                        @if ($aiType === \Modules\LBA\Models\Annotation::TYPE_POINT)
                                            <circle
                                                class="lba-ws__shape lba-ws__shape--ai"
                                                cx="{{ (float) ($geometry['x'] ?? 0) * $width }}"
                                                cy="{{ (float) ($geometry['y'] ?? 0) * $height }}"
                                                r="{{ $pointRadius }}"
                                                fill="rgba(232,121,249,0.16)"
                                                stroke="#e879f9"
                                                stroke-width="2"
                                                vector-effect="non-scaling-stroke"
                                            />
                                        @elseif ($aiType === \Modules\LBA\Models\Annotation::TYPE_RECTANGLE)
                                            <rect
                                                class="lba-ws__shape lba-ws__shape--ai"
                                                x="{{ (float) ($geometry['x'] ?? 0) * $width }}"
                                                y="{{ (float) ($geometry['y'] ?? 0) * $height }}"
                                                width="{{ (float) ($geometry['width'] ?? 0) * $width }}"
                                                height="{{ (float) ($geometry['height'] ?? 0) * $height }}"
                                                fill="rgba(232,121,249,0.14)"
                                                stroke="#e879f9"
                                                stroke-width="2"
                                                vector-effect="non-scaling-stroke"
                                            />
                                        @elseif ($aiType === \Modules\LBA\Models\Annotation::TYPE_POLYGON)
                                            @php
                                                $aiPoints = collect($geometry['points'] ?? [])
                                                    ->map(fn ($p) => ((float) ($p[0] ?? 0) * $width).','.((float) ($p[1] ?? 0) * $height))
                                                    ->implode(' ');
                                            @endphp
                                            <polygon
                                                class="lba-ws__shape lba-ws__shape--ai"
                                                points="{{ $aiPoints }}"
                                                fill="rgba(232,121,249,0.14)"
                                                stroke="#e879f9"
                                                stroke-width="2"
                                                vector-effect="non-scaling-stroke"
                                            />
                                        @endif
                                    @endforeach
                                @endif

                                {{-- Draft rectangle (client-side only) --}}
                                <rect
                                    x-show="rectStart !== null"
                                    x-cloak
                                    x-bind:x="rectX()"
                                    x-bind:y="rectY()"
                                    x-bind:width="rectW()"
                                    x-bind:height="rectH()"
                                    fill="rgba(10,165,166,0.18)"
                                    stroke="#0aa5a6"
                                    stroke-width="2"
                                    stroke-dasharray="6 4"
                                    vector-effect="non-scaling-stroke"
                                />

                                {{-- Draft polygon (client-side only) --}}
                                <polyline
                                    x-show="polygon.length > 0"
                                    x-cloak
                                    x-bind:points="polyPoints()"
                                    fill="none"
                                    stroke="#0aa5a6"
                                    stroke-width="2"
                                    stroke-dasharray="6 4"
                                    vector-effect="non-scaling-stroke"
                                />
                                <template x-for="(point, index) in polygon" x-bind:key="index">
                                    <circle
                                        x-bind:cx="point[0] * imageWidth"
                                        x-bind:cy="point[1] * imageHeight"
                                        r="3"
                                        fill="#0aa5a6"
                                        vector-effect="non-scaling-stroke"
                                    />
                                </template>
                            </svg>
                        </div>
                    </div>

                    <div class="lba-ws__zoombar">
                        <button type="button" class="lba-ws__btn" x-on:click="zoomOut()">−</button>
                        <span x-text="Math.round(zoom * 100) + '%'"></span>
                        <button type="button" class="lba-ws__btn" x-on:click="zoomIn()">+</button>
                        <button type="button" class="lba-ws__btn" x-on:click="fit()">{{ __('lba.exams.workspace.fit') }}</button>

                        <label class="lba-ws__label" for="lba-ws-brightness">{{ __('lba.exams.workspace.brightness') }}</label>
                        <input id="lba-ws-brightness" class="lba-ws__range" type="range" min="20" max="200" x-model.number="brightness">
                        <label class="lba-ws__label" for="lba-ws-contrast">{{ __('lba.exams.workspace.contrast') }}</label>
                        <input id="lba-ws-contrast" class="lba-ws__range" type="range" min="20" max="200" x-model.number="contrast">

                        <span class="lba-ws__hint">{{ __('lba.exams.workspace.view_only_hint') }}</span>
                    </div>
                    <p class="lba-ws__hint">{{ __('lba.exams.workspace.draw_hint') }}</p>
                @else
                    <p class="lba-ws__empty">{{ __('lba.exams.workspace.select_image') }}</p>
                @endif
            </section>

            {{-- RIGHT: information + annotations --}}
            <aside class="lba-ws__panel">
                <div class="lba-ws__block">
                    <p class="lba-ws__block-title">{{ __('lba.exams.workspace.info') }}</p>
                    <dl class="lba-ws__dl">
                        <dt>{{ __('lba.exams.branch') }}</dt>
                        <dd>{{ $examination->branch?->name ?? '—' }}</dd>
                        <dt>{{ __('lba.exams.captured_at') }}</dt>
                        <dd>{{ $examination->captured_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                        <dt>{{ __('lba.exams.instrument') }}</dt>
                        <dd>{{ $examination->instrument ?? '—' }}</dd>
                        <dt>{{ __('lba.exams.magnification') }}</dt>
                        <dd>{{ $examination->magnification ?? '—' }}</dd>
                        <dt>{{ __('lba.exams.image_count') }}</dt>
                        <dd>{{ $images->count() }}</dd>
                    </dl>
                </div>

                {{-- WS6.C — per-original provenance of the SELECTED image. Only
                     non-clinical engineering fields are shown: origin, capture
                     time, instrument label and integrity checksum. No patient,
                     filename or storage path is ever surfaced. --}}
                @if ($selected)
                    <div class="lba-ws__block">
                        <p class="lba-ws__block-title">{{ __('lba.exams.workspace.image_metadata') }}</p>
                        <dl class="lba-ws__dl">
                            <dt>{{ __('lba.exams.workspace.source') }}</dt>
                            <dd>{{ __('lba.exams.workspace.source_'.$selected->source) }}</dd>
                            <dt>{{ __('lba.exams.captured_at') }}</dt>
                            <dd>{{ $selected->captureMoment()?->format('Y-m-d H:i') ?? '—' }}</dd>
                            <dt>{{ __('lba.exams.instrument') }}</dt>
                            <dd>{{ $selected->instrumentName() ?? '—' }}</dd>
                            <dt>{{ __('lba.exams.workspace.checksum') }}</dt>
                            <dd><span class="lba-ws__mono">{{ $selected->checksum }}</span></dd>
                        </dl>
                    </div>
                @endif

                <div class="lba-ws__block">
                    <p class="lba-ws__block-title">{{ __('lba.exams.workspace.selected_annotation') }}</p>

                    @if ($selectedAnnotation)
                        <div class="lba-ws__field">
                            <span class="lba-ws__label">{{ __('lba.exams.annotations.type') }}</span>
                            <span>{{ __('lba.exams.annotations.type_'.$selectedAnnotation->type) }}</span>
                        </div>
                        <div class="lba-ws__field">
                            <label class="lba-ws__label" for="lba-ws-label">{{ __('lba.exams.annotations.label') }}</label>
                            <input id="lba-ws-label" type="text" class="lba-ws__input" wire:model="label" @disabled(! $canAnnotate)>
                        </div>
                        <div class="lba-ws__field">
                            <label class="lba-ws__label" for="lba-ws-notes">{{ __('lba.exams.annotations.notes') }}</label>
                            <textarea id="lba-ws-notes" class="lba-ws__textarea" wire:model="notes" @disabled(! $canAnnotate)></textarea>
                        </div>
                        <div class="lba-ws__bar-actions">
                            <button type="button" class="lba-ws__btn lba-ws__btn--primary" wire:click="saveAnnotation" @disabled(! $canAnnotate)>
                                {{ __('lba.exams.workspace.save') }}
                            </button>
                            @if ($selectedAnnotation->isManual())
                                <button type="button" class="lba-ws__btn lba-ws__btn--danger" wire:click="deleteAnnotation('{{ $selectedAnnotation->getKey() }}')" wire:confirm="{{ __('lba.exams.annotations.delete') }}?" @disabled(! $canAnnotate)>
                                    {{ __('lba.exams.annotations.delete') }}
                                </button>
                            @endif
                        </div>
                    @else
                        <div class="lba-ws__field">
                            <label class="lba-ws__label" for="lba-ws-label">{{ __('lba.exams.annotations.label') }}</label>
                            <input id="lba-ws-label" type="text" class="lba-ws__input" wire:model="label" @disabled(! $canAnnotate)>
                        </div>
                        <div class="lba-ws__field">
                            <label class="lba-ws__label" for="lba-ws-notes">{{ __('lba.exams.annotations.notes') }}</label>
                            <textarea id="lba-ws-notes" class="lba-ws__textarea" wire:model="notes" @disabled(! $canAnnotate)></textarea>
                        </div>
                        <p class="lba-ws__hint">{{ __('lba.exams.workspace.new_hint') }}</p>
                    @endif
                </div>

                <div class="lba-ws__block">
                    <p class="lba-ws__block-title">{{ __('lba.exams.annotations.title') }} ({{ $annotations->count() }})</p>
                    <ul class="lba-ws__list">
                        @forelse ($annotations as $annotation)
                            <li>
                                <button
                                    type="button"
                                    class="lba-ws__item {{ $selectedAnnotation && (string) $selectedAnnotation->getKey() === (string) $annotation->getKey() ? 'is-active' : '' }}"
                                    wire:click="selectAnnotation('{{ $annotation->getKey() }}')"
                                >
                                    <span>
                                        <span class="lba-ws__item-type">{{ __('lba.exams.annotations.type_'.$annotation->type) }} · {{ $annotation->provenance === \Modules\LBA\Models\Annotation::PROVENANCE_AI ? __('lba.exams.annotations.provenance_ai') : __('lba.exams.annotations.provenance_human') }}</span><br>
                                        {{ $annotation->label ?: '—' }}
                                    </span>
                                </button>
                            </li>
                        @empty
                            <li class="lba-ws__empty">{{ __('lba.exams.annotations.empty') }}</li>
                        @endforelse
                    </ul>
                </div>

                {{-- AI suggestions (unreviewed) --}}
                <div class="lba-ws__block">
                    <p class="lba-ws__block-title">{{ __('lba.exams.ai.heading') }}</p>

                    @if (! $aiEnabled)
                        <p class="lba-ws__empty">{{ __('lba.exams.ai.disabled_notice') }}</p>
                    @else
                        @if ($latestJob)
                            <div class="lba-ws__statusrow" style="margin-bottom: 0.625rem;">
                                <span>{{ __('lba.exams.ai.job_status') }}</span>
                                <span class="lba-ws__badge lba-ws__badge--ai">{{ __('lba.exams.ai.state_'.$latestJob->processingState()) }}</span>
                            </div>
                            <div class="lba-ws__statusrow" style="margin-bottom: 0.25rem;">
                                <span class="lba-ws__hint">{{ __('lba.exams.ai.provider') }}</span>
                                <span class="lba-ws__hint">{{ $latestJob->provider }} · {{ $latestJob->model_name ?? '—' }} {{ $latestJob->model_version ?? '' }}</span>
                            </div>
                            @if ($latestJob->error_code)
                                <div class="lba-ws__statusrow">
                                    <span class="lba-ws__hint">{{ __('lba.exams.ai.error') }}</span>
                                    <span class="lba-ws__badge lba-ws__badge--muted">{{ __('lba.exams.ai.error_'.$latestJob->error_code) }}</span>
                                </div>
                            @endif
                            @if ($latestJob->isRetryable())
                                <button type="button" class="lba-ws__btn" style="margin-top: 0.625rem;" wire:click="retryAnalysis('{{ $latestJob->getKey() }}')" wire:loading.attr="disabled">
                                    {{ __('lba.exams.ai.retry') }}
                                </button>
                            @endif
                        @else
                            <p class="lba-ws__empty">{{ __('lba.exams.ai.no_jobs') }}</p>
                        @endif

                        @if ($canStartAnalysis)
                            <button type="button" class="lba-ws__btn lba-ws__btn--primary" style="margin-top: 0.625rem;" wire:click="startAnalysis" wire:loading.attr="disabled">
                                {{ __('lba.exams.ai.start') }}
                            </button>
                        @endif

                        @if ($detections->isNotEmpty() || $aiLabelOptions !== [])
                            <div class="lba-ws__field" style="margin-top: 0.75rem;">
                                <label class="lba-ws__label" for="lba-ws-ai-label">{{ __('lba.exams.ai.filter_label') }}</label>
                                <select id="lba-ws-ai-label" class="lba-ws__input" wire:model.live="aiLabelFilter">
                                    <option value="">{{ __('lba.exams.ai.filter_label_all') }}</option>
                                    @foreach ($aiLabelOptions as $value => $text)
                                        <option value="{{ $value }}">{{ $text }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="lba-ws__field">
                                <label class="lba-ws__label" for="lba-ws-ai-conf">
                                    {{ __('lba.exams.ai.filter_confidence') }}: {{ round($this->aiMinConfidence * 100) }}%
                                </label>
                                <input id="lba-ws-ai-conf" type="range" min="0" max="1" step="0.05" class="lba-ws__range" wire:model.live="aiMinConfidence">
                            </div>
                        @endif

                        <ul class="lba-ws__list">
                            @forelse ($detections as $detection)
                                <li class="lba-ws__suggestion">
                                    <span class="lba-ws__suggestion-label">{{ $detection->label }}</span>
                                    <span class="lba-ws__suggestion-conf">{{ $detection->confidencePercent() }}</span>
                                </li>
                            @empty
                                <li class="lba-ws__empty">{{ __('lba.exams.ai.no_suggestions') }}</li>
                            @endforelse
                        </ul>
                        <p class="lba-ws__hint">{{ __('lba.exams.ai.disclaimer') }}</p>
                    @endif
                </div>
            </aside>
        </div>
    </div>

    <script>
        function lbaWorkspace(config) {
            return {
                imageWidth: config.imageWidth || 1000,
                imageHeight: config.imageHeight || 1000,
                canAnnotate: !!config.canAnnotate,
                zoom: 1,
                panX: 0,
                panY: 0,
                brightness: 100,
                contrast: 100,
                panning: false,
                panStartX: 0,
                panStartY: 0,
                panOriginX: 0,
                panOriginY: 0,
                rectStart: null,
                rectCurrent: null,
                polygon: [],

                canvasStyle() {
                    return `transform: translate(${this.panX}px, ${this.panY}px) scale(${this.zoom});`;
                },

                imageStyle() {
                    return `filter: brightness(${this.brightness}%) contrast(${this.contrast}%);`;
                },

                norm(event) {
                    const rect = this.$refs.canvas.getBoundingClientRect();
                    if (rect.width <= 0 || rect.height <= 0) {
                        return { x: 0, y: 0 };
                    }
                    return {
                        x: Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)),
                        y: Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height)),
                    };
                },

                pointerDown(event) {
                    const tool = this.$wire.tool;

                    if (event.button === 1 || (event.button === 0 && tool === 'select')) {
                        if (event.target.closest('[wire\\:click]')) {
                            return;
                        }
                        this.startPan(event);
                        return;
                    }

                    if (event.button !== 0 || !this.canAnnotate) {
                        return;
                    }

                    const point = this.norm(event);

                    if (tool === 'point') {
                        this.$wire.createAnnotation('point', { x: point.x, y: point.y });
                    } else if (tool === 'rectangle') {
                        this.rectStart = point;
                        this.rectCurrent = point;
                    } else if (tool === 'polygon') {
                        this.polygon.push([point.x, point.y]);
                    }
                },

                pointerMove(event) {
                    if (this.panning) {
                        this.panX = this.panOriginX + (event.clientX - this.panStartX);
                        this.panY = this.panOriginY + (event.clientY - this.panStartY);
                        return;
                    }

                    if (this.rectStart !== null) {
                        this.rectCurrent = this.norm(event);
                    }
                },

                pointerUp(event) {
                    if (this.panning) {
                        this.panning = false;
                        return;
                    }

                    if (this.rectStart !== null) {
                        const start = this.rectStart;
                        const end = this.norm(event);
                        const x = Math.min(start.x, end.x);
                        const y = Math.min(start.y, end.y);
                        const width = Math.abs(end.x - start.x);
                        const height = Math.abs(end.y - start.y);

                        this.rectStart = null;
                        this.rectCurrent = null;

                        if (width > 0.005 && height > 0.005 && this.canAnnotate) {
                            this.$wire.createAnnotation('rectangle', { x, y, width, height });
                        }
                    }
                },

                startPan(event) {
                    this.panning = true;
                    this.panStartX = event.clientX;
                    this.panStartY = event.clientY;
                    this.panOriginX = this.panX;
                    this.panOriginY = this.panY;
                },

                zoomIn() { this.zoom = Math.min(8, this.zoom * 1.25); },
                zoomOut() { this.zoom = Math.max(0.25, this.zoom / 1.25); },
                fit() { this.zoom = 1; this.panX = 0; this.panY = 0; },

                rectX() { return this.rectStart && this.rectCurrent ? Math.min(this.rectStart.x, this.rectCurrent.x) * this.imageWidth : 0; },
                rectY() { return this.rectStart && this.rectCurrent ? Math.min(this.rectStart.y, this.rectCurrent.y) * this.imageHeight : 0; },
                rectW() { return this.rectStart && this.rectCurrent ? Math.abs(this.rectCurrent.x - this.rectStart.x) * this.imageWidth : 0; },
                rectH() { return this.rectStart && this.rectCurrent ? Math.abs(this.rectCurrent.y - this.rectStart.y) * this.imageHeight : 0; },
                polyPoints() { return this.polygon.map((p) => `${p[0] * this.imageWidth},${p[1] * this.imageHeight}`).join(' '); },

                undoPoint() { this.polygon.pop(); },

                finishPolygon() {
                    if (this.polygon.length >= 3 && this.canAnnotate) {
                        this.$wire.createAnnotation('polygon', { points: this.polygon });
                    }
                    this.polygon = [];
                },
            };
        }

        /**
         * WS6.A — local-only microscope camera capture.
         *
         * The live preview is purely client-side: `navigator.mediaDevices` is used
         * only in a secure context, the stream is never recorded or broadcast, and
         * every media track is stopped when the operator stops, switches camera or
         * leaves the page. A still is captured to an off-screen canvas and handed
         * to Livewire's authenticated, CSRF-protected upload; the server then runs
         * the same validated private-intake path as a manual import. Where no
         * compatible camera exists, or permission is denied, the operator gets a
         * clear fallback message and manual file import stays available.
         */
        function lbaCapture(config) {
            return {
                supported: false,
                devices: [],
                deviceId: '',
                streaming: false,
                busy: false,
                uploading: false,
                progress: 0,
                error: '',
                stream: null,
                msgs: config.msgs || { denied: '', notFound: '', generic: '' },

                init() {
                    this.supported = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
                    if (this.supported) {
                        this.enumerate();
                    }
                },

                async enumerate() {
                    try {
                        const devices = await navigator.mediaDevices.enumerateDevices();
                        this.devices = devices.filter((d) => d.kind === 'videoinput');
                        if (!this.deviceId && this.devices.length) {
                            this.deviceId = this.devices[0].deviceId;
                        }
                    } catch (e) {
                        this.devices = [];
                    }
                },

                async start() {
                    if (!this.supported) {
                        return;
                    }

                    this.error = '';
                    this.busy = true;

                    try {
                        const constraints = {
                            video: this.deviceId ? { deviceId: { exact: this.deviceId } } : true,
                            audio: false,
                        };
                        this.stream = await navigator.mediaDevices.getUserMedia(constraints);
                        this.$refs.video.srcObject = this.stream;
                        await this.$refs.video.play().catch(() => {});
                        this.streaming = true;
                        await this.enumerate();
                    } catch (e) {
                        this.error = this.errText(e);
                        this.stop();
                    } finally {
                        this.busy = false;
                    }
                },

                stop() {
                    if (this.stream) {
                        this.stream.getTracks().forEach((track) => track.stop());
                        this.stream = null;
                    }
                    this.streaming = false;
                },

                async restart() {
                    if (this.streaming) {
                        this.stop();
                        await this.start();
                    }
                },

                capture() {
                    const video = this.$refs.video;

                    if (!video || !video.videoWidth || !video.videoHeight) {
                        return;
                    }

                    const canvas = this.$refs.canvas;
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

                    this.busy = true;
                    this.error = '';

                    canvas.toBlob((blob) => {
                        if (!blob) {
                            this.busy = false;
                            this.error = this.msgs.generic;
                            return;
                        }

                        const file = new File([blob], 'capture-' + Date.now() + '.jpg', { type: 'image/jpeg' });

                        this.uploading = true;
                        this.progress = 0;

                        this.$wire.upload(
                            'capturedImage',
                            file,
                            () => {
                                this.uploading = false;
                                this.busy = false;
                                this.$wire.storeCapturedImage(this.deviceLabel());
                            },
                            () => {
                                this.uploading = false;
                                this.busy = false;
                                this.error = this.msgs.generic;
                            },
                            (event) => {
                                this.progress = event.detail.progress;
                            },
                        );
                    }, 'image/jpeg', 0.92);
                },

                errText(e) {
                    if (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError')) {
                        return this.msgs.denied;
                    }
                    if (e && (e.name === 'NotFoundError' || e.name === 'OverconstrainedError')) {
                        return this.msgs.notFound;
                    }
                    return this.msgs.generic;
                },

                /**
                 * The operator-facing label of the currently selected capture
                 * device, sent with the still as non-clinical display metadata
                 * only.
                 */
                deviceLabel() {
                    const device = this.devices.find((d) => d.deviceId === this.deviceId);
                    return device && device.label ? device.label : '';
                },
            };
        }
    </script>
</x-filament-panels::page>

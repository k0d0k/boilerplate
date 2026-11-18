{{--
    WS9.D — clinic knowledge console.

    Operator-facing retrieval AUDIT: it shows only document provenance
    (title, page, score, citation link) for an operator-typed query. It never
    shows a patient message or a patient identifier, and every hit is resolved
    against the verified current tenant, so cross-tenant reads are impossible.
--}}
<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 p-4 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
        {{ __('lba.knowledge.console_notice') }}
    </div>

    @if ($this->tenantId() === null)
        <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-500/10">
            <p class="text-sm text-gray-700 dark:text-gray-200">{{ __('lba.knowledge.console_no_tenant') }}</p>
        </div>
    @else
        <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-query">
                        {{ __('lba.knowledge.console_query_label') }}
                    </label>
                    <input
                        id="kb-query"
                        type="text"
                        class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5"
                        wire:model="query"
                        placeholder="{{ __('lba.knowledge.console_query_placeholder') }}"
                    />
                    @error('query') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-lang">
                        {{ __('lba.knowledge.console_language') }}
                    </label>
                    <select id="kb-lang" class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5" wire:model="language">
                        <option value="">{{ __('lba.knowledge.console_language_any') }}</option>
                        @foreach ($this->languageOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <x-filament::button wire:click="runProbe" wire:loading.attr="disabled">
                    {{ __('lba.knowledge.console_run') }}
                </x-filament::button>
            </div>

            @php($examples = $this->exampleQuestions())
            @if ($examples !== [])
                <div class="mt-4 border-t border-gray-100 pt-3 dark:border-white/10">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('lba.knowledge.console_examples') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($examples as $example)
                            <button
                                type="button"
                                class="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5"
                                wire:click="useExample(@js($example))"
                            >{{ $example }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        @if ($probed)
            <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('lba.knowledge.console_results') }}</p>

                @if ($results === [])
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('lba.knowledge.console_no_results') }}</p>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase tracking-wide text-gray-400">
                                <tr>
                                    <th class="py-2 pr-4">{{ __('lba.knowledge.console_column_source') }}</th>
                                    <th class="py-2 pr-4">{{ __('lba.knowledge.console_column_page') }}</th>
                                    <th class="py-2 pr-4">{{ __('lba.knowledge.console_column_score') }}</th>
                                    <th class="py-2 pr-4">{{ __('lba.knowledge.console_column_scope') }}</th>
                                    <th class="py-2">{{ __('lba.knowledge.console_column_open') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($results as $row)
                                    <tr class="border-t border-gray-100 dark:border-white/10">
                                        <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $row['title'] }}</td>
                                        <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['page'] }}</td>
                                        <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['score'] }}</td>
                                        <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">
                                            {{ $row['platform'] ? __('lba.knowledge.console_scope_platform') : __('lba.knowledge.console_scope_tenant') }}
                                        </td>
                                        <td class="py-2">
                                            @if ($row['url'] !== '')
                                                <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="underline">
                                                    {{ __('lba.knowledge.console_open') }}
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('lba.knowledge.console_documents_title') }}</p>

            @php($documents = $this->documents())
            @if ($documents === [])
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('lba.knowledge.console_documents_empty') }}</p>
            @else
                <div class="mt-2 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-gray-400">
                            <tr>
                                <th class="py-2 pr-4">{{ __('lba.knowledge.console_column_title') }}</th>
                                <th class="py-2 pr-4">{{ __('lba.knowledge.console_column_status') }}</th>
                                <th class="py-2 pr-4">{{ __('lba.knowledge.console_column_version') }}</th>
                                <th class="py-2 pr-4">{{ __('lba.knowledge.console_column_pages') }}</th>
                                <th class="py-2 pr-4">{{ __('lba.knowledge.console_column_chunks') }}</th>
                                <th class="py-2 pr-4">{{ __('lba.knowledge.console_column_failed') }}</th>
                                <th class="py-2">{{ __('lba.knowledge.console_column_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($documents as $document)
                                <tr class="border-t border-gray-100 dark:border-white/10">
                                    <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $document['title'] }}</td>
                                    <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $document['status'] }}</td>
                                    <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $document['version'] }}</td>
                                    <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $document['page_count'] }}</td>
                                    <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $document['embedded'] }}/{{ $document['chunks'] }}</td>
                                    <td class="py-2 pr-4 {{ $document['failed'] > 0 ? 'text-red-500' : 'text-gray-600 dark:text-gray-300' }}">{{ $document['failed'] }}</td>
                                    <td class="py-2">
                                        @if ($document['retryable'])
                                            <button
                                                type="button"
                                                class="rounded-lg border border-gray-200 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5"
                                                wire:click="retryIndex(@js($document['id']))"
                                            >{{ __('lba.knowledge.console_retry') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>

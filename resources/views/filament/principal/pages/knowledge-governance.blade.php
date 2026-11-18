{{--
    WS9.D — Principal platform-knowledge governance.

    Curates PLATFORM-scope reference knowledge only. Every action re-derives the
    principal's `platform.knowledge.manage` permission server-side; this view is
    never the authorization boundary. No tenant or patient data is shown.
--}}
<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 p-4 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
        {{ __('lba.principal.knowledge.notice') }}
    </div>

    {{-- Collections --}}
    <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
        <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('lba.principal.knowledge.collections_title') }}</p>

        @php($collections = $this->collections())
        @if ($collections === [])
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('lba.principal.knowledge.collections_empty') }}</p>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">{{ __('lba.knowledge.name') }}</th>
                            <th class="py-2 pr-4">{{ __('lba.knowledge.language') }}</th>
                            <th class="py-2 pr-4">{{ __('lba.principal.knowledge.column_documents') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($collections as $collection)
                            <tr class="border-t border-gray-100 dark:border-white/10">
                                <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $collection['name'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $collection['language'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $collection['documents'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-collection-name">
                    {{ __('lba.principal.knowledge.collection_name') }}
                </label>
                <input id="kb-collection-name" type="text" class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5" wire:model="collectionName" />
                @error('collectionName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-collection-lang">
                    {{ __('lba.knowledge.language') }}
                </label>
                <select id="kb-collection-lang" class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5" wire:model="collectionLanguage">
                    @foreach ($this->languageOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('collectionLanguage') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-3">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-collection-desc">
                    {{ __('lba.knowledge.description') }}
                </label>
                <textarea id="kb-collection-desc" rows="2" class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5" wire:model="collectionDescription"></textarea>
                @error('collectionDescription') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-3">
            <x-filament::button wire:click="createCollection" wire:loading.attr="disabled">
                {{ __('lba.principal.knowledge.create_collection') }}
            </x-filament::button>
        </div>
    </div>

    {{-- Platform PDF ingestion --}}
    <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
        <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('lba.principal.knowledge.upload_title') }}</p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('lba.principal.knowledge.upload_hint') }}</p>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-doc-collection">
                    {{ __('lba.knowledge.document_collection') }}
                </label>
                <select id="kb-doc-collection" class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5" wire:model="collectionId">
                    <option value="">{{ __('lba.principal.knowledge.select_collection') }}</option>
                    @foreach ($this->collectionOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                @error('collectionId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-doc-title">
                    {{ __('lba.knowledge.title') }}
                </label>
                <input id="kb-doc-title" type="text" class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5" wire:model="documentTitle" />
                @error('documentTitle') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-doc-author">
                    {{ __('lba.knowledge.author') }}
                </label>
                <input id="kb-doc-author" type="text" class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5" wire:model="documentAuthor" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-doc-publication">
                    {{ __('lba.knowledge.publication') }}
                </label>
                <input id="kb-doc-publication" type="text" class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5" wire:model="documentPublication" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-doc-license">
                    {{ __('lba.knowledge.license') }}
                </label>
                <input id="kb-doc-license" type="text" class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5" wire:model="documentLicense" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-doc-source">
                    {{ __('lba.knowledge.source_url') }}
                </label>
                <input id="kb-doc-source" type="text" class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5" wire:model="documentSourceUrl" />
                @error('documentSourceUrl') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400" for="kb-doc-pdf">
                    {{ __('lba.knowledge.document_pdf') }}
                </label>
                <input id="kb-doc-pdf" type="file" accept="application/pdf" class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/10 dark:bg-white/5" wire:model="pdf" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('lba.knowledge.document_pdf_hint') }}</p>
                @error('pdf') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-3">
            <x-filament::button wire:click="uploadDocument" wire:loading.attr="disabled">
                {{ __('lba.principal.knowledge.upload_action') }}
            </x-filament::button>
        </div>
    </div>

    {{-- Documents --}}
    <div class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
        <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('lba.principal.knowledge.documents_title') }}</p>

        @php($documents = $this->documents())
        @if ($documents === [])
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('lba.principal.knowledge.documents_empty') }}</p>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">{{ __('lba.knowledge.title') }}</th>
                            <th class="py-2 pr-4">{{ __('lba.knowledge.document_collection') }}</th>
                            <th class="py-2 pr-4">{{ __('lba.knowledge.status') }}</th>
                            <th class="py-2 pr-4">{{ __('lba.knowledge.document_version') }}</th>
                            <th class="py-2 pr-4">{{ __('lba.knowledge.document_pages') }}</th>
                            <th class="py-2 pr-4">{{ __('lba.principal.knowledge.column_chunks') }}</th>
                            <th class="py-2">{{ __('lba.principal.knowledge.column_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            <tr class="border-t border-gray-100 align-top dark:border-white/10">
                                <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $document['title'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $document['collection'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $document['status'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $document['version'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $document['page_count'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $document['embedded'] }}/{{ $document['chunks'] }}</td>
                                <td class="py-2">
                                    <div class="flex flex-wrap gap-1">
                                        @if ($document['can_publish'])
                                            <button type="button" class="rounded-lg border border-gray-200 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5" wire:click="publishDocument(@js($document['id']))">{{ __('lba.knowledge.action_publish') }}</button>
                                        @endif
                                        @if ($document['can_index'])
                                            <button type="button" class="rounded-lg border border-gray-200 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5" wire:click="reindexDocument(@js($document['id']))">{{ __('lba.principal.knowledge.reindex') }}</button>
                                        @endif
                                        @if ($document['can_archive'])
                                            <button type="button" class="rounded-lg border border-gray-200 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5" wire:click="archiveDocument(@js($document['id']))">{{ __('lba.knowledge.action_archive') }}</button>
                                        @endif
                                        @if ($document['can_revoke'])
                                            <button type="button" class="rounded-lg border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-500/30 dark:text-red-300 dark:hover:bg-red-500/10" wire:click="revokeDocument(@js($document['id']))">{{ __('lba.knowledge.action_revoke') }}</button>
                                        @endif
                                        <button type="button" class="rounded-lg border border-gray-200 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5" wire:click="toggleVersionComparison(@js($document['id']))">{{ __('lba.principal.knowledge.versions') }}</button>
                                    </div>

                                    @if ($comparingDocumentId === $document['id'])
                                        @php($versions = $this->versionComparison())
                                        <div class="mt-2 rounded-lg border border-gray-100 p-2 dark:border-white/10">
                                            @if ($versions === [])
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('lba.principal.knowledge.versions_empty') }}</p>
                                            @else
                                                <table class="w-full text-left text-xs">
                                                    <thead class="uppercase tracking-wide text-gray-400">
                                                        <tr>
                                                            <th class="py-1 pr-3">{{ __('lba.knowledge.document_version') }}</th>
                                                            <th class="py-1 pr-3">{{ __('lba.knowledge.document_pages') }}</th>
                                                            <th class="py-1 pr-3">{{ __('lba.principal.knowledge.column_chunks') }}</th>
                                                            <th class="py-1">{{ __('lba.principal.knowledge.column_current') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($versions as $version)
                                                            <tr class="border-t border-gray-100 dark:border-white/10">
                                                                <td class="py-1 pr-3">{{ $version['version'] }}</td>
                                                                <td class="py-1 pr-3">{{ $version['page_count'] }}</td>
                                                                <td class="py-1 pr-3">{{ $version['chunks'] }}</td>
                                                                <td class="py-1">{{ $version['current'] ? '✓' : '' }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>

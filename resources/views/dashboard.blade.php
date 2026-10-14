<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Mes projets') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if ($modules->isEmpty())
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-gray-600">
                    {{ __('Aucun projet activé. Ajoutez-en dans config/hub.php.') }}
                </div>
            @else
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($modules as $module)
                        <a href="{{ route($module->entryRoute()) }}"
                           class="group block bg-white shadow-sm sm:rounded-lg p-6 border border-transparent hover:border-indigo-300 hover:shadow-md transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                            <div class="text-4xl" aria-hidden="true">{{ $module->icon() }}</div>
                            <h3 class="mt-4 text-lg font-semibold text-gray-900 group-hover:text-indigo-600">
                                {{ $module->name() }}
                            </h3>
                            @if ($module->description())
                                <p class="mt-2 text-sm text-gray-600">{{ $module->description() }}</p>
                            @endif
                            <p class="mt-4 text-xs font-mono text-gray-400">/{{ $module->slug() }}</p>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

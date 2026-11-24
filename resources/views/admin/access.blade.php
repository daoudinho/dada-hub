<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Comptes et accès') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="bg-green-50 text-green-800 border border-green-200 rounded-lg p-4 text-sm" role="status">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-50 text-red-800 border border-red-200 rounded-lg p-4 text-sm" role="alert">{{ $errors->first() }}</div>
            @endif

            <p class="text-sm text-gray-600">
                {{ __('Un administrateur a accès à tous les modules et à cette page. Un membre ne voit que les modules cochés. Les sous-modules (Running, Fitness…) se cochent pour chacun, administrateur compris.') }}
            </p>

            @foreach ($users as $user)
                <form method="POST" action="{{ route('admin.access.update', $user) }}" class="bg-white shadow-sm sm:rounded-lg p-6">
                    @csrf
                    @method('PUT')

                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">{{ $user->name }}</h3>
                            <p class="text-sm text-gray-500">{{ $user->email }}</p>
                        </div>

                        <label class="text-sm text-gray-700">
                            <span class="sr-only">{{ __('Rôle') }}</span>
                            <select name="role" class="rounded-md border-gray-300 text-sm">
                                <option value="member" @selected(! $user->isAdmin())>{{ __('Membre') }}</option>
                                <option value="admin" @selected($user->isAdmin())>{{ __('Administrateur') }}</option>
                            </select>
                        </label>
                    </div>

                    <fieldset class="mt-4">
                        <legend class="text-sm font-medium text-gray-700">{{ __('Modules ouverts') }}</legend>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($modules as $module)
                                <div>
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="modules[]" value="{{ $module->slug() }}" class="rounded border-gray-300"
                                               @checked(in_array($module->slug(), $user->moduleSlugs(), true))>
                                        <span aria-hidden="true">{{ $module->icon() }}</span> {{ $module->name() }}
                                    </label>
                                    @if ($module->features())
                                        <div class="mt-1 ms-6 flex flex-wrap gap-x-4 gap-y-1">
                                            @foreach ($module->features() as $feature => $label)
                                                <label class="inline-flex items-center gap-2 text-xs text-gray-600">
                                                    <input type="checkbox" name="features[{{ $module->slug() }}][]" value="{{ $feature }}" class="rounded border-gray-300"
                                                           @checked($user->hasModuleFeature($module->slug(), $feature))>
                                                    {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="mt-4">
                        <x-primary-button>{{ __('Enregistrer') }}</x-primary-button>
                    </div>
                </form>
            @endforeach
        </div>
    </div>
</x-app-layout>

@extends('layouts.app')

@section('title', 'User Management')
@section('subtitle', 'Manage user accounts')

@section('content')

{{-- Header + Search + Add Button --}}
<div class="bg-white rounded-xl shadow-sm mb-0">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-6 py-4 border-b">
        <div>
            <h2 class="text-sm font-semibold text-gray-800">All Users</h2>
            <p class="text-xs text-gray-400"><x-count-label :count="$users->total()" noun="account" total /></p>
        </div>
        <div class="flex items-center gap-3">
            <div class="relative">
                <input type="text" id="userSearch"
                    placeholder="Search users..."
                    class="border rounded-lg pl-9 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400 w-64">
                <i class="bi bi-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
            </div>
            <button type="button" onclick="openAddModal()"
                class="bg-brand-700 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-800 whitespace-nowrap">
                <i class="bi bi-plus-lg"></i> Add User
            </button>
        </div>
    </div>

    <div class="overflow-x-auto">
    <table class="w-full min-w-full text-sm" id="userTable">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
            <tr>
                <th scope="col" class="text-left px-6 py-3">Last Name</th>
                <th scope="col" class="text-left px-6 py-3">First Name</th>
                <th scope="col" class="text-left px-6 py-3">Middle Name</th>
                <th scope="col" class="text-left px-6 py-3">Email</th>
                <th scope="col" class="text-left px-6 py-3">Role</th>
                <th scope="col" class="text-left px-6 py-3">Status</th>
                <th scope="col" class="px-6 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100" id="userTableBody">
            @forelse($users as $user)
            <tr class="hover:bg-gray-50 user-row">
                <td class="px-6 py-3 font-medium text-gray-800">{{ $user->last_name ?? '—' }}</td>
                <td class="px-6 py-3 text-gray-800">{{ $user->first_name ?? '—' }}</td>
                <td class="px-6 py-3 text-gray-500">{{ $user->middle_name ?? '—' }}</td>
                <td class="px-6 py-3 text-gray-500">{{ $user->email }}</td>
                <td class="px-6 py-3">
                    @if($user->role === 'admin')
                        <span class="px-2 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-700">
                            <i class="bi bi-shield-lock-fill"></i> Admin
                        </span>
                    @elseif($user->role === 'principal')
                        <span class="px-2 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">
                            <i class="bi bi-mortarboard-fill"></i> Principal
                        </span>
                    @else
                        <span class="px-2 py-1 rounded-full text-xs font-semibold bg-brand-100 text-brand-700">
                            <i class="bi bi-person-fill"></i> Adviser
                        </span>
                    @endif
                </td>
                <td class="px-6 py-3">
                    @if($user->is_active)
                        <span class="px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700">
                            <i class="bi bi-check-circle-fill"></i> Active
                        </span>
                    @else
                        <span class="px-2 py-1 rounded-full text-xs font-semibold bg-gray-200 text-gray-600">
                            <i class="bi bi-slash-circle-fill"></i> Inactive
                        </span>
                    @endif
                </td>
                <td class="px-6 py-3 text-right">
                    <div class="flex items-center justify-end gap-2 flex-wrap">
                        <button type="button"
                            onclick='openUserEditModal(@json($user))'
                            class="inline-flex items-center gap-1 text-brand-600 hover:text-brand-800 text-xs font-medium border border-brand-200 rounded px-2 py-1 hover:bg-brand-50 whitespace-nowrap">
                            <i class="bi bi-pencil-square"></i> Edit
                        </button>

                        @if($user->id !== auth()->id())
                            @if($user->is_active)
                            <form method="POST" action="{{ route('admin.users.disable', $user->id) }}" class="inline"
                                data-confirm="Disable {{ $user->first_name }} {{ $user->last_name }}? They will no longer be able to log in."
                                data-confirm-label="Yes, Disable"
                                data-confirm-icon="bi-slash-circle"
                                data-confirm-loading-label="Disabling...">
                                @csrf
                                @method('POST')
                                <button type="submit"
                                    class="inline-flex items-center gap-1 text-orange-600 hover:text-orange-800 text-xs font-medium border border-orange-200 rounded px-2 py-1 hover:bg-orange-50 whitespace-nowrap">
                                    <i class="bi bi-slash-circle"></i> Disable
                                </button>
                            </form>
                            @else
                            <form method="POST" action="{{ route('admin.users.activate', $user->id) }}" class="inline">
                                @csrf
                                <button type="submit"
                                    class="inline-flex items-center gap-1 text-green-600 hover:text-green-800 text-xs font-medium border border-green-200 rounded px-2 py-1 hover:bg-green-50 whitespace-nowrap">
                                    <i class="bi bi-check-circle"></i> Activate
                                </button>
                            </form>
                            @endif

                        <form method="POST"
                            action="{{ route('admin.users.destroy', $user->id) }}"
                            class="inline"
                            data-confirm="Remove user {{ $user->first_name }} {{ $user->last_name }}?">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                class="inline-flex items-center gap-1 text-red-600 hover:text-red-800 text-xs font-medium border border-red-200 rounded px-2 py-1 hover:bg-red-50 whitespace-nowrap">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </form>
                        @endif
                    </div>
                </td>
            </tr>
            @empty
            <tr id="emptyRow">
                <td colspan="7">
                    <x-empty-state message="No users yet." icon="bi-people"
                        hint="Use &quot;Add User&quot; above — every account is created here by an Admin; public registration is disabled." />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <div id="noResults" class="hidden px-6 py-8 text-center text-gray-400">
        <i class="bi bi-search text-2xl block mb-2"></i>
        No users found matching your search.
    </div>

    @if($users->hasPages())
    <div class="px-6 py-4 border-t flex items-center justify-center gap-2 text-sm">
        @if($users->onFirstPage())
            <span class="px-3 py-1 rounded border text-gray-300 cursor-not-allowed">← Prev</span>
        @else
            <a href="{{ $users->previousPageUrl() }}"
               class="px-3 py-1 rounded border hover:bg-gray-50 text-gray-600">← Prev</a>
        @endif
        <span class="px-3 py-1 rounded border bg-brand-700 text-white font-medium">
            {{ $users->currentPage() }}
        </span>
        @if($users->hasMorePages())
            <a href="{{ $users->nextPageUrl() }}"
               class="px-3 py-1 rounded border hover:bg-gray-50 text-gray-600">Next →</a>
        @else
            <span class="px-3 py-1 rounded border text-gray-300 cursor-not-allowed">Next →</span>
        @endif
    </div>
    @endif
</div>

{{-- ADD / EDIT MODAL --}}
<div id="userModal"
     class="{{ $errors->any() ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div id="userModalBox" class="modal-box {{ $errors->any() ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-lg w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 id="modalTitle" class="text-lg font-semibold text-gray-800">
                <i class="bi bi-plus-lg"></i> Add User
            </h3>
            <button type="button" onclick="closeUserModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if($errors->any())
            <div class="bg-red-100 text-red-700 text-sm p-3 rounded-lg mb-4">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form id="userForm" method="POST"
              data-store-url="{{ route('admin.users.store') }}"
              class="space-y-4">
            @csrf
            <input type="hidden" name="_method" id="formMethod" value="POST">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Last Name</label>
                    <input type="text" name="last_name" id="field_last_name" required
                           value="{{ old('last_name') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">First Name</label>
                    <input type="text" name="first_name" id="field_first_name" required
                           value="{{ old('first_name') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Middle Name</label>
                <input type="text" name="middle_name" id="field_middle_name"
                       value="{{ old('middle_name') }}"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Username</label>
                <div class="flex items-center border rounded-lg overflow-hidden focus-within:ring-2 focus-within:ring-brand-400">
                    <input type="text" name="username" id="field_username" required
                           value="{{ old('username') }}"
                           placeholder="e.g. juan.delacruz"
                           class="flex-1 px-3 py-2 text-sm outline-none">
                    <span class="bg-gray-100 px-3 py-2 text-sm text-gray-500 border-l">
                        @naggasican.edu.ph
                    </span>
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Role</label>
                <select name="role" id="field_role" required
                        class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
                    <option value="adviser">Adviser</option>
                    <option value="admin">Admin</option>
                    <option value="principal">Principal</option>
                </select>
            </div>

            <div>
                <label class="block text-sm text-gray-600 mb-1">Password
                    <span class="text-gray-400 text-xs">(minimum 8 characters)</span>
                </label>
                <input type="password" name="password" id="field_password"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400"
                       placeholder="Enter password">
                <p id="passwordNote" class="text-xs text-gray-400 mt-1 hidden">
                    Leave blank to keep current password
                </p>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeUserModal()"
                        class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700">Cancel</button>
                <button type="submit" id="submitBtn"
                        class="bg-brand-700 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-brand-800">
                    Save User
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTableSearch('userSearch', '.user-row', 'noResults');
    });
</script>
@endpush

@endsection
@extends('layouts.app')

@section('title', 'User Management')
@section('subtitle', 'Manage user accounts')

@section('content')

{{-- Header + Search + Add Button --}}
<div class="card mb-0">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-5 py-4 border-b border-line">
        <div>
            <h2 class="card-title">All Users</h2>
            <p class="text-xs text-muted"><x-count-label :count="$users->total()" noun="account" total /></p>
        </div>
        <div class="flex items-center gap-3">
            <div class="relative">
                <input type="text" id="userSearch"
                    placeholder="Search users..."
                    class="form-input !w-64 pl-9">
                <i class="bi bi-search absolute left-3 top-2.5 text-gray-400 text-sm"></i>
            </div>
            <button type="button" onclick="openAddModal()"
                class="btn btn-primary whitespace-nowrap">
                <i class="bi bi-plus-lg"></i> Add User
            </button>
        </div>
    </div>

    <div class="tbl-scroll">
    <table class="tbl" id="userTable">
        <thead>
            <tr>
                <th scope="col">Last Name</th>
                <th scope="col">First Name</th>
                <th scope="col">Middle Name</th>
                <th scope="col">Email</th>
                <th scope="col">Role</th>
                <th scope="col">Status</th>
                <th scope="col" class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody id="userTableBody">
            @forelse($users as $user)
            <tr class="user-row">
                <td class="font-medium text-ink">{{ $user->last_name ?? '—' }}</td>
                <td>{{ $user->first_name ?? '—' }}</td>
                <td class="text-gray-500">{{ $user->middle_name ?? '—' }}</td>
                <td class="text-gray-500">{{ $user->email }}</td>
                <td>
                    @if($user->role === 'admin')
                        <span class="badge bg-purple-100 text-purple-700">
                            <i class="bi bi-shield-lock-fill"></i> Admin
                        </span>
                    @elseif($user->role === 'principal')
                        <span class="badge badge-warning">
                            <i class="bi bi-mortarboard-fill"></i> Principal
                        </span>
                    @else
                        <span class="px-2 py-1 rounded-full text-xs font-semibold bg-brand-100 text-brand-700">
                            <i class="bi bi-person-fill"></i> Adviser
                        </span>
                    @endif
                </td>
                <td>
                    @if($user->is_active)
                        <span class="badge badge-success">
                            <i class="bi bi-check-circle-fill"></i> Active
                        </span>
                    @else
                        <span class="badge badge-gray">
                            <i class="bi bi-slash-circle-fill"></i> Inactive
                        </span>
                    @endif
                </td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-2 flex-wrap">
                        <button type="button"
                            onclick='openUserEditModal(@json($user))'
                            class="inline-flex items-center gap-1 btn btn-xs btn-secondary whitespace-nowrap">
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
                                    class="btn btn-xs bg-warning-soft text-warning-text border border-amber-200 hover:bg-amber-100">
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
                                class="inline-flex items-center gap-1 btn btn-xs btn-danger-outline whitespace-nowrap">
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
                        hint='Use "Add User" above — every account is created here by an Admin; public registration is disabled.' />
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
    <div class="px-5 py-4 border-t border-line flex items-center justify-center gap-2 text-sm">
        @if($users->onFirstPage())
            <span class="px-3 py-1 rounded-md border border-line text-gray-300 cursor-not-allowed">← Prev</span>
        @else
            <a href="{{ $users->previousPageUrl() }}"
               class="px-3 py-1 rounded-md border border-line hover:bg-surface text-gray-600">← Prev</a>
        @endif
        <span class="px-3 py-1 rounded-md border border-brand-800 bg-brand-800 text-white font-medium">
            {{ $users->currentPage() }}
        </span>
        @if($users->hasMorePages())
            <a href="{{ $users->nextPageUrl() }}"
               class="px-3 py-1 rounded-md border border-line hover:bg-surface text-gray-600">Next →</a>
        @else
            <span class="px-3 py-1 rounded-md border border-line text-gray-300 cursor-not-allowed">Next →</span>
        @endif
    </div>
    @endif
</div>

{{-- ADD / EDIT MODAL --}}
<div id="userModal"
     class="{{ ($errors->any() && !$errors->has('deletion')) ? 'opacity-100' : 'hidden opacity-0' }} fixed inset-0 bg-black/40 flex items-center justify-center z-50 transition-opacity duration-200">
    <div id="userModalBox" class="modal-box {{ ($errors->any() && !$errors->has('deletion')) ? 'scale-100 opacity-100' : 'scale-95 opacity-0' }} bg-white rounded-xl shadow-modal w-full max-w-lg p-6 transition-all duration-200">

        <div class="flex justify-between items-center mb-4">
            <h3 id="modalTitle" class="text-lg font-semibold text-ink">
                <i class="bi bi-plus-lg"></i> Add User
            </h3>
            <button type="button" onclick="closeUserModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>

        @if(($errors->any() && !$errors->has('deletion')))
            <div class="alert alert-danger mb-4">
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
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" id="field_last_name" required
                           value="{{ old('last_name') }}"
                           class="form-input">
                </div>
                <div>
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" id="field_first_name" required
                           value="{{ old('first_name') }}"
                           class="form-input">
                </div>
            </div>

            <div>
                <label class="form-label">Middle Name</label>
                <input type="text" name="middle_name" id="field_middle_name"
                       value="{{ old('middle_name') }}"
                       class="form-input">
            </div>

            <div>
                <label class="form-label">Username</label>
                <div class="flex items-center border rounded-lg overflow-hidden focus-within:ring-2 focus-within:ring-brand-400">
                    <input type="text" name="username" id="field_username" required
                           value="{{ old('username') }}"
                           placeholder="e.g. juan.delacruz"
                           class="flex-1 px-3 py-2 text-sm outline-none">
                    <span class="bg-gray-100 px-3 py-2 text-sm text-muted border-l">
                        @naggasican.edu.ph
                    </span>
                </div>
            </div>

            <div>
                <label class="form-label">Role</label>
                <select name="role" id="field_role" required
                        class="form-input">
                    <option value="adviser">Adviser</option>
                    <option value="admin">Admin</option>
                    <option value="principal">Principal</option>
                </select>
            </div>

            <div>
                <label class="form-label">Password
                    <span class="text-gray-400 text-xs">(minimum 8 characters)</span>
                </label>
                <input type="password" name="password" id="field_password"
                       class="form-input"
                       placeholder="Enter password">
                <p id="passwordNote" class="text-xs text-muted mt-1 hidden">
                    Leave blank to keep current password
                </p>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeUserModal()"
                        class="btn btn-outline">Cancel</button>
                <button type="submit" id="submitBtn"
                        class="btn btn-primary">
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
@extends('admin.layout')

@section('title', ($reward->exists ? 'Изменить награду' : 'Новая награда') . ' — CIO Loyalty')

@section('content')
    @php($isEdit = $reward->exists)

    <h1>{{ $isEdit ? 'Изменить награду' : 'Новая награда' }}</h1>

    <form class="card" method="POST" action="{{ $isEdit ? route('admin.rewards.update', $reward) : route('admin.rewards.store') }}">
        @csrf
        @if ($isEdit)
            @method('PATCH')
        @endif

        @if (auth()->user()?->brand_id === null)
            <div class="field">
                <label for="brand_id">Бренд</label>
                <select id="brand_id" name="brand_id" required>
                    <option value="">— выберите бренд —</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected((string) old('brand_id', $reward->brand_id) === (string) $brand->id)>
                            {{ $brand->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="field">
            <label for="title">Название</label>
            <input id="title" name="title" value="{{ old('title', $reward->title) }}" required maxlength="120">
        </div>

        <div class="field">
            <label for="description">Описание</label>
            <textarea id="description" name="description" rows="3" maxlength="1000">{{ old('description', $reward->description) }}</textarea>
        </div>

        <div class="row">
            <div class="field">
                <label for="cost_points">Стоимость, баллов</label>
                <input id="cost_points" type="number" name="cost_points" min="1" value="{{ old('cost_points', $reward->cost_points) }}" required>
            </div>

            <div class="field">
                <label for="stock">Остаток (пусто — не ограничен)</label>
                <input id="stock" type="number" name="stock" min="0" value="{{ old('stock', $reward->stock) }}">
            </div>
        </div>

        <div class="field">
            <label for="image_url">Ссылка на изображение</label>
            <input id="image_url" name="image_url" value="{{ old('image_url', $reward->image_url) }}">
        </div>

        <div class="field">
            <label>
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" style="width: auto;" @checked(old('is_active', $reward->is_active))>
                Награда активна
            </label>
        </div>

        <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Сохранить' : 'Создать' }}</button>
        <a class="btn" href="{{ route('admin.rewards.index') }}">Отмена</a>
    </form>
@endsection

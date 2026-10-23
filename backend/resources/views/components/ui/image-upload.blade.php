@props([
    'model' => 'image',
    'file' => null,
    'previewUrl' => null,
    'currentUrl' => null,
    'focalX' => 50,
    'focalY' => 50,
    'label' => 'Image',
    'hint' => null,
    'required' => false,
])

{{--
    A 16:9 image picker for Livewire forms: choose a file, see exactly how it
    will be cropped, click the preview to choose which part stays in frame
    (same rule as CSS object-position, which the server applies), and watch the
    upload progress. $file is the Livewire temporary upload (or null); the
    focal point lives in the parent's focalX/focalY properties.
--}}
<div
    x-data="{
        uploading: false,
        progress: 0,
        fx: {{ (int) $focalX }},
        fy: {{ (int) $focalY }},
        pick(event) {
            const box = event.currentTarget.getBoundingClientRect();
            this.fx = Math.round(Math.min(100, Math.max(0, (event.clientX - box.left) / box.width * 100)));
            this.fy = Math.round(Math.min(100, Math.max(0, (event.clientY - box.top) / box.height * 100)));
            $wire.set('focalX', this.fx, false);
            $wire.set('focalY', this.fy, false);
        },
    }"
    x-on:livewire-upload-start="uploading = true; progress = 0"
    x-on:livewire-upload-progress="progress = $event.detail.progress"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-error="uploading = false"
    x-on:livewire-upload-cancel="uploading = false"
    class="sm:col-span-2 space-y-3"
>
    <div class="flex items-baseline gap-1.5 text-xs font-medium text-fg-2">
        <span id="{{ $model }}-label">{{ $label }}</span>
        @if ($required)<span class="text-danger" aria-hidden="true">*</span><span class="sr-only">(required)</span>@endif
    </div>

    <div class="relative aspect-video w-full max-w-xl overflow-hidden rounded-xl border border-line-strong bg-surface-2">
        @if ($previewUrl)
            <img
                src="{{ $previewUrl }}"
                alt="Preview of the card as players will see it"
                class="size-full cursor-crosshair object-cover"
                :style="`object-position: ${fx}% ${fy}%`"
                x-on:click="pick($event)"
            >
            <span
                class="pointer-events-none absolute size-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-fg shadow-[0_0_0_1px_var(--mm-bg)]"
                :style="`left: ${fx}%; top: ${fy}%`"
                aria-hidden="true"
            ></span>
        @elseif ($currentUrl)
            <img src="{{ $currentUrl }}" alt="Current card image" class="size-full object-cover">
        @else
            <div class="flex size-full flex-col items-center justify-center gap-2 text-fg-3">
                <x-icon name="upload" :size="26" />
                <span class="text-xs">A 16:9 preview appears here</span>
            </div>
        @endif

        <div x-show="uploading" x-cloak class="mm-glass-bar absolute inset-x-0 bottom-0 p-3" role="progressbar" aria-label="Upload progress" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
            <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full rounded-full bg-brand transition-[width]" :style="`width: ${progress}%`"></div></div>
            <p class="mt-1.5 text-xs text-fg-2">Uploading… <span x-text="progress + '%'"></span></p>
        </div>
    </div>

    @if ($previewUrl)
        <p class="max-w-xl text-xs text-fg-3">Click the preview to choose which part of the picture stays in frame. Anything outside 16:9 is cropped, never stretched.</p>
    @elseif ($currentUrl)
        <p class="max-w-xl text-xs text-fg-3">Choose a new file to replace this image. The crop position can be set after you pick a file.</p>
    @endif

    <input
        type="file"
        id="{{ $model }}"
        wire:model="{{ $model }}"
        accept="image/jpeg,image/png,image/webp"
        aria-labelledby="{{ $model }}-label"
        @if ($errors->has($model)) aria-invalid="true" aria-describedby="{{ $model }}-error" @endif
        class="mm-control max-w-xl"
    >

    @if ($hint)<p class="max-w-xl text-xs text-fg-3">{{ $hint }}</p>@endif
    @error($model)
        <p id="{{ $model }}-error" class="flex max-w-xl items-start gap-1.5 text-xs text-danger" role="alert"><x-icon name="x-circle" :size="14" class="mt-px shrink-0" /><span>{{ $message }}</span></p>
    @enderror
</div>

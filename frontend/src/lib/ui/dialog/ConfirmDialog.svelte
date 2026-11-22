<script lang="ts">
    import type { Snippet } from 'svelte';
    import Dialog from './Dialog.svelte';
    import Button from '$lib/ui/button/Button.svelte';
    import Alert from '$lib/components/Alert.svelte';
    import { httpErrorToHuman } from '$lib/api/http';

    type Props = {
        open?: boolean;
        title: string;
        confirmLabel?: string;
        /** Runs on confirm. The dialog stays open with the error shown if it throws. */
        onConfirm: () => Promise<void> | void;
        /**
         * Called once the dialog has closed after a successful confirmation. Work that
         * takes the page down belongs here rather than in `onConfirm`: the page must not
         * disappear while the dialog is still on screen, or the dialog is left behind.
         */
        onClosed?: () => void;
        /** Disables the confirm button, e.g. until a required field is filled. */
        disabled?: boolean;
        children?: Snippet;
    };

    let {
        open = $bindable(false),
        title,
        confirmLabel = '确认',
        onConfirm,
        onClosed,
        disabled = false,
        children,
    }: Props = $props();

    let busy = $state(false);
    let error = $state('');
    /** Whether the dialog is closing because a confirmation went through. */
    let confirmed = $state(false);

    // A dialog that is opened again has not been confirmed yet.
    $effect(() => {
        if (open) confirmed = false;
    });

    async function confirm() {
        busy = true;
        error = '';
        try {
            await onConfirm();
            confirmed = true;
            open = false;
        } catch (e) {
            confirmed = false;
            error = httpErrorToHuman(e);
        } finally {
            busy = false;
        }
    }
</script>

<Dialog
    bind:open
    {title}
    preventClose={busy}
    onOpenChange={o => o || (error = '')}
    onClosed={() => {
        if (!confirmed) return;

        confirmed = false;
        onClosed?.();
    }}
>
    <div class="space-y-4 text-sm">
        {#if error}
            <Alert variant="destructive">{error}</Alert>
        {/if}
        {@render children?.()}
    </div>

    {#snippet footer()}
        <Button variant="ghost" size="sm" disabled={busy} onclick={() => (open = false)}>取消</Button>
        <Button variant="destructive" size="sm" loading={busy} {disabled} onclick={confirm}>{confirmLabel}</Button>
    {/snippet}
</Dialog>

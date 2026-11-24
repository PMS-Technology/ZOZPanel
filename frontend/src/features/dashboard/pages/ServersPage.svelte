<script lang="ts">
    import { createQuery, keepPreviousData } from '@tanstack/svelte-query';
    import { Layers, Search, ServerOff } from '@lucide/svelte';
    import { getServers } from '$lib/api/servers';
    import { getServerGroups } from '$lib/api/groups';
    import { app } from '$lib/stores/app.svelte';
    import { httpErrorToHuman } from '$lib/api/http';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import ServerCard from '../components/ServerCard.svelte';
    import ServerGroupDialog from '../components/ServerGroupDialog.svelte';
    import { Checkbox, Spinner } from '$lib/ui';
    import Alert from '$lib/components/Alert.svelte';
    import { stagger } from '$lib/motion/stagger';

    let query = $state('');
    let showAll = $state(false);
    let page = $state(1);

    // Debounce the filter so typing does not fire a request per keystroke.
    let debounced = $state('');
    $effect(() => {
        const value = query;
        const timer = setTimeout(() => {
            debounced = value;
            // A new search starts at the first page.
            page = 1;
        }, 300);

        return () => clearTimeout(timer);
    });

    const serversQuery = createQuery(() => ({
        queryKey: ['servers', { query: debounced, showAll, page }],
        queryFn: () =>
            getServers({
                query: debounced || undefined,
                page,
                // `type=admin-all` is the backend's flag for listing every server, and is
                // only honoured for accounts that hold admin access.
                ...(showAll && app.isAdmin ? { type: 'admin-all' } : {}),
            }),
        // The cards of the page being left stay on screen while the next one loads.
        placeholderData: keepPreviousData,
    }));

    const servers = $derived(serversQuery.data?.items ?? []);

    // The last page takes over when the page being shown is gone, e.g. after a server
    // was deleted elsewhere.
    $effect(() => {
        const totalPages = serversQuery.data?.pagination.totalPages;

        if (totalPages && page > totalPages) {
            page = totalPages;
        }
    });

    // Group badges on the cards; the dialog refetches this cache after any mutation.
    const groupsQuery = createQuery(() => ({
        queryKey: ['server-groups'],
        queryFn: getServerGroups,
        enabled: false,
    }));
    const groups = $derived(groupsQuery.data ?? []);

    let groupsOpen = $state(false);
    let assignServerUuid = $state<string | null>(null);
</script>

<div class="mx-auto max-w-7xl p-6 lg:p-8">
    <PageHeader title="控制台" description="你有权访问的服务器。">
        <div class="flex items-center gap-2">
            <div class="relative">
                <Search
                    class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    type="search"
                    bind:value={query}
                    placeholder="搜索服务器"
                    aria-label="搜索服务器"
                    class="h-9 w-56 rounded-md border border-input bg-background pl-8 pr-3 text-sm
                           placeholder:text-muted-foreground focus-visible:outline-none
                           focus-visible:ring-2 focus-visible:ring-ring"
                />
            </div>

            {#if app.isAdmin}
                <Checkbox
                    bind:checked={showAll}
                    onCheckedChange={() => (page = 1)}
                    label="显示全部"
                    wrapperClass="text-sm text-muted-foreground"
                />
            {/if}

            <button
                type="button"
                class="inline-flex h-9 items-center gap-2 rounded-md border border-input bg-background px-3 text-sm
                       text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                onclick={() => (groupsOpen = true)}
            >
                <Layers class="size-4" />
                分组
            </button>
        </div>
    </PageHeader>

    {#if serversQuery.isPending}
        <Spinner centered />
    {:else if serversQuery.isError}
        <Alert variant="destructive" title="无法加载服务器列表">
            {httpErrorToHuman(serversQuery.error)}
        </Alert>
    {:else if servers.length === 0}
        <div class="flex flex-col items-center gap-3 rounded-lg border border-dashed border-border py-16 text-center">
            <ServerOff class="size-6 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">
                {debounced ? '没有匹配的服务器。' : '你的账号下还没有服务器。'}
            </p>
        </div>
    {:else}
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" use:stagger={{ distance: 10 }}>
            {#each servers as server (server.id)}
                <ServerCard
                    {server}
                    group={groups.find(group => group.id === server.groupId)}
                    onAssign={() => (assignServerUuid = server.uuid)}
                />
            {/each}
        </div>

        <Pagination
            pagination={serversQuery.data?.pagination}
            currentPage={page}
            onPageChange={p => (page = p)}
        />
    {/if}
</div>

<ServerGroupDialog
    bind:open={groupsOpen}
    {assignServerUuid}
    onAssignClose={() => (assignServerUuid = null)}
/>

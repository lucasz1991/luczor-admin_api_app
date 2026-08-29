const NETWORK_SELECTOR = '[data-memory-network-3d]';
const initializedNetworks = new WeakSet();
const activeNetworkCleanups = new Set();

const SCOPE_COLORS = {
    project: '#22d3ee',
    private: '#a78bfa',
    skill: '#fbbf24',
    agent: '#38bdf8',
    global: '#34d399',
    device: '#fb7185',
    user: '#f472b6',
    workspace: '#60a5fa',
    session: '#c084fc',
};

const HUB_COLORS = {
    project: '#38bdf8',
    type: '#34d399',
    scope: '#a78bfa',
};

const normalize = (value) => String(value ?? '')
    .toLocaleLowerCase('de-DE')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');

const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, value));

function hashUnit(value) {
    let hash = 2166136261;
    const input = String(value);

    for (let index = 0; index < input.length; index += 1) {
        hash ^= input.charCodeAt(index);
        hash = Math.imul(hash, 16777619);
    }

    return ((hash >>> 0) % 10000) / 10000;
}

function ringPosition(index, total, radius, phase = 0) {
    const angle = phase + (Math.PI * 2 * index) / Math.max(1, total);

    return {
        y: Math.sin(angle) * radius,
        z: Math.cos(angle) * radius,
    };
}

function createGraph(memories) {
    const nodes = [];
    const links = [];
    const nodeById = new Map();
    const memoryById = new Map();
    const hubMaps = {
        project: new Map(),
        type: new Map(),
        scope: new Map(),
    };

    const uniqueLabels = (key) => [...new Set(memories.map((memory) => memory[key] || 'Unbekannt'))]
        .sort((left, right) => left.localeCompare(right, 'de'));
    const projectEntries = [...new Map(memories.map((memory) => {
        const key = memory.project_key || `label:${memory.project || 'Unbekannt'}`;
        return [key, { key, label: memory.project || 'Unbekannt' }];
    })).values()].sort((left, right) => left.label.localeCompare(right.label, 'de') || left.key.localeCompare(right.key, 'de'));

    const labels = {
        type: uniqueLabels('type'),
        scope: uniqueLabels('scope'),
    };

    projectEntries.forEach(({ key, label }, index) => {
        const ring = ringPosition(index, projectEntries.length, 165, -0.45);
        const node = {
            id: `project:${key}`,
            kind: 'project',
            label,
            x: -235,
            y: ring.y,
            z: ring.z,
            visible: true,
        };
        nodes.push(node);
        nodeById.set(node.id, node);
        hubMaps.project.set(key, node);
    });

    labels.type.forEach((label, index) => {
        const ring = ringPosition(index, labels.type.length, 165, Math.PI + 0.45);
        const node = {
            id: `type:${label}`,
            kind: 'type',
            label,
            x: 235,
            y: ring.y,
            z: ring.z,
            visible: true,
        };
        nodes.push(node);
        nodeById.set(node.id, node);
        hubMaps.type.set(label, node);
    });

    labels.scope.forEach((label, index) => {
        const angle = (Math.PI * 2 * index) / Math.max(1, labels.scope.length) - Math.PI / 2;
        const node = {
            id: `scope:${label}`,
            kind: 'scope',
            label,
            x: Math.cos(angle) * 92,
            y: Math.sin(angle) * 210,
            z: -155 + Math.sin(angle * 2) * 45,
            visible: true,
        };
        nodes.push(node);
        nodeById.set(node.id, node);
        hubMaps.scope.set(label, node);
    });

    memories.forEach((memory) => {
        const project = hubMaps.project.get(memory.project_key || `label:${memory.project || 'Unbekannt'}`);
        const type = hubMaps.type.get(memory.type || 'Unbekannt');
        const scope = hubMaps.scope.get(memory.scope || 'Unbekannt');
        const seed = `memory:${memory.id}`;
        const node = {
            id: seed,
            kind: 'memory',
            label: memory.summary || `Memory #${memory.id}`,
            memory,
            x: ((project?.x ?? 0) + (type?.x ?? 0) + (scope?.x ?? 0)) / 3 + (hashUnit(`${seed}:x`) - 0.5) * 115,
            y: ((project?.y ?? 0) + (type?.y ?? 0) + (scope?.y ?? 0)) / 3 + (hashUnit(`${seed}:y`) - 0.5) * 110,
            z: ((project?.z ?? 0) + (type?.z ?? 0) + (scope?.z ?? 0)) / 3 + (hashUnit(`${seed}:z`) - 0.5) * 130,
            visible: true,
        };

        nodes.push(node);
        nodeById.set(node.id, node);
        memoryById.set(String(memory.id), node);

        [
            ['project', project],
            ['type', type],
            ['scope', scope],
        ].forEach(([kind, hub]) => {
            if (hub) {
                links.push({ source: node, target: hub, kind, visible: true });
            }
        });
    });

    memories.forEach((memory) => {
        const source = memoryById.get(String(memory.id));
        const target = memoryById.get(String(memory.supersedes_id));

        if (source && target) {
            links.push({ source, target, kind: 'version', visible: true });
        }
    });

    return { nodes, links, nodeById, memoryById, hubMaps };
}

function initializeNetwork(root) {
    if (initializedNetworks.has(root)) {
        return;
    }

    const canvas = root.querySelector('[data-memory-network-canvas]');
    const payload = root.querySelector('[data-memory-network-payload]');

    if (!canvas || !payload) {
        initializedNetworks.add(root);
        return;
    }

    let memories;

    try {
        memories = JSON.parse(payload.textContent || '[]');
    } catch {
        return;
    }

    if (!Array.isArray(memories) || memories.length === 0) {
        initializedNetworks.add(root);
        return;
    }

    initializedNetworks.add(root);

    const context = canvas.getContext('2d');
    const graph = createGraph(memories);
    const listButtons = new Map(
        [...root.querySelectorAll('[data-memory-network-list-item]')]
            .map((button) => [String(button.dataset.memoryNetworkListItem), button]),
    );
    const scopeButtons = [...root.querySelectorAll('[data-memory-network-scope]')];
    const searchInput = root.querySelector('[data-memory-network-search]');
    const visibleOutput = root.querySelector('[data-memory-network-visible]');
    const noResults = root.querySelector('[data-memory-network-no-results]');
    const inspector = root.querySelector('[data-memory-network-inspector]');
    const announcer = root.querySelector('[data-memory-network-announcer]');
    const inspectorEmpty = root.querySelector('[data-memory-network-inspector-empty]');
    const inspectorDetail = root.querySelector('[data-memory-network-inspector-detail]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const activePointers = new Map();

    let width = 0;
    let height = 0;
    let rotationX = reducedMotion ? -0.12 : -0.18;
    let rotationY = reducedMotion ? 0.34 : 0.52;
    let zoom = 1;
    let selectedNode = null;
    let hoveredNode = null;
    let selectedScope = 'all';
    let searchTerm = '';
    let renderFrame = null;
    let dragPointer = null;
    let dragMoved = false;
    let previousPinchDistance = null;

    memories.forEach((memory) => {
        memory._search = normalize([
            memory.summary,
            memory.project,
            memory.type,
            memory.scope,
            memory.feature_key,
            memory.dataset,
        ].filter(Boolean).join(' '));
    });

    function project(node) {
        const cosY = Math.cos(rotationY);
        const sinY = Math.sin(rotationY);
        const xY = node.x * cosY - node.z * sinY;
        const zY = node.x * sinY + node.z * cosY;
        const cosX = Math.cos(rotationX);
        const sinX = Math.sin(rotationX);
        const yX = node.y * cosX - zY * sinX;
        const zX = node.y * sinX + zY * cosX;
        const baseScale = Math.min(width / 650, height / 510) * zoom;
        const perspective = 720 / Math.max(390, 720 - zX);

        return {
            x: width / 2 + xY * baseScale * perspective,
            y: height / 2 + yX * baseScale * perspective,
            z: zX,
            perspective,
            scale: baseScale,
        };
    }

    function drawReferenceRing(axisA, axisB, radius, color) {
        const segments = 72;
        context.beginPath();

        for (let index = 0; index <= segments; index += 1) {
            const angle = (Math.PI * 2 * index) / segments;
            const point = { x: 0, y: 0, z: 0 };
            point[axisA] = Math.cos(angle) * radius;
            point[axisB] = Math.sin(angle) * radius;
            const screen = project(point);

            if (index === 0) {
                context.moveTo(screen.x, screen.y);
            } else {
                context.lineTo(screen.x, screen.y);
            }
        }

        context.strokeStyle = color;
        context.lineWidth = 0.7;
        context.stroke();
    }

    function connectedToSelection(link) {
        return selectedNode && (link.source === selectedNode || link.target === selectedNode);
    }

    function isNeighbor(node) {
        if (!selectedNode || node === selectedNode) {
            return Boolean(selectedNode && node === selectedNode);
        }

        return graph.links.some((link) => link.visible
            && connectedToSelection(link)
            && (link.source === node || link.target === node));
    }

    function drawLink(link) {
        const source = link.source._screen;
        const target = link.target._screen;
        const highlighted = connectedToSelection(link);
        const isVersion = link.kind === 'version';

        context.beginPath();
        context.moveTo(source.x, source.y);
        context.lineTo(target.x, target.y);
        context.setLineDash(isVersion ? [] : [2.5, 4.5]);
        context.strokeStyle = isVersion ? '#fbbf24' : (HUB_COLORS[link.kind] || '#7dd3fc');
        context.globalAlpha = selectedNode ? (highlighted ? 0.78 : 0.035) : (isVersion ? 0.62 : 0.13);
        context.lineWidth = highlighted ? 1.55 : (isVersion ? 1.25 : 0.75);
        context.stroke();
        context.setLineDash([]);
        context.globalAlpha = 1;
    }

    function drawHubShape(node, radius) {
        context.beginPath();

        if (node.kind === 'project') {
            context.moveTo(node._screen.x, node._screen.y - radius);
            context.lineTo(node._screen.x + radius, node._screen.y);
            context.lineTo(node._screen.x, node._screen.y + radius);
            context.lineTo(node._screen.x - radius, node._screen.y);
            context.closePath();
        } else if (node.kind === 'type') {
            context.rect(node._screen.x - radius * 0.75, node._screen.y - radius * 0.75, radius * 1.5, radius * 1.5);
        } else {
            const sides = 6;
            for (let side = 0; side < sides; side += 1) {
                const angle = -Math.PI / 2 + (Math.PI * 2 * side) / sides;
                const x = node._screen.x + Math.cos(angle) * radius;
                const y = node._screen.y + Math.sin(angle) * radius;
                if (side === 0) context.moveTo(x, y);
                else context.lineTo(x, y);
            }
            context.closePath();
        }
    }

    function drawLabel(node, isSelected) {
        const shouldLabel = node.kind !== 'memory' || isSelected || node === hoveredNode;

        if (!shouldLabel || (width < 500 && !isSelected && node !== hoveredNode)) {
            return;
        }

        const rawLabel = node.kind === 'memory' ? node.label : node.label;
        const maximum = node.kind === 'memory' ? 34 : 22;
        const label = rawLabel.length > maximum ? `${rawLabel.slice(0, maximum - 1)}…` : rawLabel;
        let x = node._screen.x + 10;
        const y = node._screen.y - 7;
        context.font = `${node.kind === 'memory' ? 10 : 9}px ui-monospace, SFMono-Regular, Menlo, monospace`;
        const textWidth = context.measureText(label).width;
        if (x + textWidth + 4 > width) {
            x = node._screen.x - textWidth - 10;
        }
        context.fillStyle = 'rgba(2, 10, 17, 0.84)';
        context.fillRect(x - 4, y - 10, textWidth + 8, 16);
        context.fillStyle = isSelected ? '#ecfeff' : '#789cad';
        context.globalAlpha = isSelected ? 1 : 0.82;
        context.fillText(label, x, y + 1);
        context.globalAlpha = 1;
    }

    function drawNode(node) {
        const isSelected = node === selectedNode;
        const neighbor = isNeighbor(node);
        const depthScale = clamp(Math.sqrt(node._screen.perspective), 0.72, 1.35);
        const importance = clamp(Number(node.memory?.importance ?? 0.5), 0, 1);
        const baseRadius = node.kind === 'memory' ? 2.6 + importance * 4.4 : 7.2;
        const radius = baseRadius * depthScale;
        const color = node.kind === 'memory'
            ? (SCOPE_COLORS[node.memory.scope] || '#94a3b8')
            : HUB_COLORS[node.kind];

        node._screen.radius = radius;
        context.globalAlpha = selectedNode && !isSelected && !neighbor ? 0.24 : clamp(0.7 + node._screen.perspective * 0.18, 0.65, 1);

        if (isSelected || node === hoveredNode) {
            context.beginPath();
            context.arc(node._screen.x, node._screen.y, radius + (isSelected ? 7 : 4), 0, Math.PI * 2);
            context.fillStyle = color;
            context.globalAlpha = isSelected ? 0.12 : 0.07;
            context.fill();
            context.globalAlpha = 1;
        }

        if (node.kind === 'memory') {
            context.beginPath();
            context.arc(node._screen.x, node._screen.y, radius, 0, Math.PI * 2);
        } else {
            drawHubShape(node, radius);
        }

        context.fillStyle = color;
        context.fill();
        context.strokeStyle = isSelected ? '#f8fdff' : 'rgba(3, 10, 16, 0.92)';
        context.lineWidth = isSelected ? 1.4 : 1;
        context.stroke();
        context.globalAlpha = 1;
        drawLabel(node, isSelected);
    }

    function render() {
        renderFrame = null;
        context.clearRect(0, 0, width, height);

        if (!width || !height) {
            return;
        }

        drawReferenceRing('x', 'z', 235, 'rgba(64, 155, 181, 0.085)');
        drawReferenceRing('y', 'z', 205, 'rgba(109, 91, 190, 0.07)');
        drawReferenceRing('x', 'y', 220, 'rgba(57, 211, 174, 0.055)');

        graph.nodes.filter((node) => node.visible).forEach((node) => {
            node._screen = project(node);
        });

        graph.links
            .filter((link) => link.visible)
            .sort((left, right) => ((left.source._screen.z + left.target._screen.z) / 2) - ((right.source._screen.z + right.target._screen.z) / 2))
            .forEach(drawLink);

        graph.nodes
            .filter((node) => node.visible)
            .sort((left, right) => left._screen.z - right._screen.z)
            .forEach(drawNode);
    }

    function scheduleRender() {
        if (renderFrame === null) {
            renderFrame = window.requestAnimationFrame(render);
        }
    }

    function resize() {
        const bounds = canvas.getBoundingClientRect();
        const nextWidth = Math.max(1, Math.round(bounds.width));
        const nextHeight = Math.max(1, Math.round(bounds.height));
        const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);

        if (nextWidth === width && nextHeight === height && canvas.width === nextWidth * pixelRatio) {
            return;
        }

        width = nextWidth;
        height = nextHeight;
        canvas.width = Math.round(width * pixelRatio);
        canvas.height = Math.round(height * pixelRatio);
        context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
        scheduleRender();
    }

    function setDetailText(attribute, value) {
        const element = inspector?.querySelector(`[${attribute}]`);
        if (element) element.textContent = value ?? '—';
    }

    function showMemoryDetail(node) {
        const memory = node.memory;
        const scopeColor = SCOPE_COLORS[memory.scope] || '#94a3b8';
        inspector?.style.setProperty('--detail-scope-color', scopeColor);
        setDetailText('data-memory-detail-scope', memory.scope || 'Unbekannt');
        setDetailText('data-memory-detail-status', memory.status || 'Unbekannt');
        setDetailText('data-memory-detail-title', `Memory #${memory.id}`);
        setDetailText('data-memory-detail-summary', memory.summary || 'Keine Zusammenfassung vorhanden.');
        setDetailText('data-memory-detail-project', memory.project);
        setDetailText('data-memory-detail-type', memory.type);
        setDetailText('data-memory-detail-feature', memory.feature_key || '—');
        setDetailText('data-memory-detail-importance', Number(memory.importance ?? 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        setDetailText('data-memory-detail-confidence', memory.confidence === null || memory.confidence === undefined
            ? '—'
            : `${Math.round(Number(memory.confidence) * 100)} %`);
        setDetailText('data-memory-detail-staleness', memory.staleness || 'Unbekannt');
        setDetailText('data-memory-detail-projection', memory.cognee_projected
            ? `${memory.projection_status || 'projiziert'} · verbunden`
            : (memory.projection_status || 'nicht verbunden'));
        setDetailText('data-memory-detail-source', memory.source_type || 'Unbekannt');
        setDetailText('data-memory-detail-updated', memory.updated_at || '—');
        setDetailText('data-memory-detail-dataset', memory.dataset || '—');

        const version = inspector?.querySelector('[data-memory-detail-version]');
        if (version) {
            version.hidden = !memory.supersedes_id;
            version.textContent = memory.supersedes_id
                ? `Echte Versionsbeziehung: Dieses Memory ersetzt Memory #${memory.supersedes_id}.`
                : '';
        }
        if (announcer) {
            announcer.textContent = `Memory #${memory.id} ausgewählt. ${memory.summary || 'Keine Zusammenfassung vorhanden.'}`;
        }
    }

    function relatedMemories(node) {
        return graph.links
            .filter((link) => link.visible && (link.source === node || link.target === node))
            .map((link) => link.source.kind === 'memory' ? link.source : link.target)
            .filter((related, index, entries) => related.kind === 'memory' && entries.indexOf(related) === index);
    }

    function showHubDetail(node) {
        const related = relatedMemories(node);
        inspector?.style.setProperty('--detail-scope-color', HUB_COLORS[node.kind] || '#94a3b8');
        setDetailText('data-memory-detail-scope', 'Metadaten-Hub');
        setDetailText('data-memory-detail-status', node.kind);
        setDetailText('data-memory-detail-title', node.label);
        setDetailText('data-memory-detail-summary', `${related.length} sichtbare Memories sind über die Metadaten-Kategorie „${node.label}“ verbunden.`);
        setDetailText('data-memory-detail-project', node.kind === 'project' ? node.label : '—');
        setDetailText('data-memory-detail-type', node.kind === 'type' ? node.label : '—');
        setDetailText('data-memory-detail-feature', '—');
        setDetailText('data-memory-detail-importance', '—');
        setDetailText('data-memory-detail-confidence', '—');
        setDetailText('data-memory-detail-staleness', '—');
        setDetailText('data-memory-detail-projection', 'Keine semantische Cognee-Kante');
        setDetailText('data-memory-detail-source', 'SQL-Metadaten');
        setDetailText('data-memory-detail-updated', '—');
        setDetailText('data-memory-detail-dataset', '—');
        const version = inspector?.querySelector('[data-memory-detail-version]');
        if (version) version.hidden = true;
        if (announcer) {
            announcer.textContent = `${node.kind}-Hub ${node.label} ausgewählt. ${related.length} sichtbare Memories.`;
        }
    }

    function selectNode(node) {
        selectedNode = node;
        inspectorEmpty?.setAttribute('hidden', '');
        inspectorDetail?.removeAttribute('hidden');

        listButtons.forEach((button) => button.removeAttribute('aria-current'));
        if (node.kind === 'memory') {
            listButtons.get(String(node.memory.id))?.setAttribute('aria-current', 'true');
            showMemoryDetail(node);
        } else {
            showHubDetail(node);
        }

        scheduleRender();
    }

    function clearSelection() {
        selectedNode = null;
        inspectorEmpty?.removeAttribute('hidden');
        inspectorDetail?.setAttribute('hidden', '');
        listButtons.forEach((button) => button.removeAttribute('aria-current'));
        scheduleRender();
    }

    function matchesFilters(memory) {
        const scopeMatches = selectedScope === 'all' || memory.scope === selectedScope;
        const searchMatches = !searchTerm || memory._search.includes(searchTerm);

        return scopeMatches && searchMatches;
    }

    function applyFilters() {
        const visibleMemoryIds = new Set();

        graph.memoryById.forEach((node, id) => {
            node.visible = matchesFilters(node.memory);
            if (node.visible) visibleMemoryIds.add(id);
        });

        ['project', 'type', 'scope'].forEach((kind) => {
            graph.hubMaps[kind].forEach((hub) => {
                hub.visible = graph.links.some((link) => link.kind === kind
                    && link.target === hub
                    && link.source.visible);
            });
        });

        graph.links.forEach((link) => {
            link.visible = link.source.visible && link.target.visible;
        });

        listButtons.forEach((button, id) => {
            const memoryNode = graph.memoryById.get(id);
            button.hidden = !memoryNode?.visible;
        });

        if (visibleOutput) visibleOutput.textContent = String(visibleMemoryIds.size);
        if (noResults) noResults.hidden = visibleMemoryIds.size > 0;

        if (selectedNode && !selectedNode.visible) {
            clearSelection();
        } else {
            if (selectedNode && selectedNode.kind !== 'memory') {
                showHubDetail(selectedNode);
            }
            scheduleRender();
        }
    }

    function hitTest(clientX, clientY) {
        const bounds = canvas.getBoundingClientRect();
        const x = clientX - bounds.left;
        const y = clientY - bounds.top;
        let closest = null;
        let closestDistance = Number.POSITIVE_INFINITY;

        graph.nodes
            .filter((node) => node.visible && node._screen)
            .forEach((node) => {
                const distance = Math.hypot(x - node._screen.x, y - node._screen.y);
                const hitRadius = Math.max(10, (node._screen.radius || 4) + 6);
                if (distance <= hitRadius && distance < closestDistance) {
                    closest = node;
                    closestDistance = distance;
                }
            });

        return closest;
    }

    canvas.addEventListener('pointerdown', (event) => {
        canvas.setPointerCapture(event.pointerId);
        activePointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        dragPointer = { id: event.pointerId, x: event.clientX, y: event.clientY };
        dragMoved = false;
        previousPinchDistance = null;
    });

    canvas.addEventListener('pointermove', (event) => {
        if (!activePointers.has(event.pointerId)) {
            const nextHovered = hitTest(event.clientX, event.clientY);
            if (nextHovered !== hoveredNode) {
                hoveredNode = nextHovered;
                canvas.style.cursor = hoveredNode ? 'pointer' : 'grab';
                scheduleRender();
            }
            return;
        }

        activePointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

        if (activePointers.size === 2) {
            const points = [...activePointers.values()];
            const distance = Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y);
            if (previousPinchDistance !== null) {
                zoom = clamp(zoom * (distance / previousPinchDistance), 0.58, 1.9);
                dragMoved = true;
                scheduleRender();
            }
            previousPinchDistance = distance;
            return;
        }

        if (!dragPointer || dragPointer.id !== event.pointerId) {
            return;
        }

        const deltaX = event.clientX - dragPointer.x;
        const deltaY = event.clientY - dragPointer.y;
        if (Math.abs(deltaX) + Math.abs(deltaY) > 1) dragMoved = true;
        rotationY += deltaX * 0.008;
        rotationX = clamp(rotationX + deltaY * 0.006, -1.18, 1.18);
        dragPointer.x = event.clientX;
        dragPointer.y = event.clientY;
        scheduleRender();
    });

    function releasePointer(event) {
        const shouldSelect = !dragMoved && activePointers.size === 1;
        activePointers.delete(event.pointerId);

        if (shouldSelect) {
            const node = hitTest(event.clientX, event.clientY);
            if (node) selectNode(node);
            else clearSelection();
        }

        if (activePointers.size === 0) {
            dragPointer = null;
            previousPinchDistance = null;
            canvas.style.cursor = hoveredNode ? 'pointer' : 'grab';
        } else if (activePointers.size === 1) {
            const [remainingId, remainingPoint] = activePointers.entries().next().value;
            dragPointer = { id: remainingId, x: remainingPoint.x, y: remainingPoint.y };
            previousPinchDistance = null;
            dragMoved = true;
        }
    }

    canvas.addEventListener('pointerup', releasePointer);
    canvas.addEventListener('pointercancel', releasePointer);
    canvas.addEventListener('wheel', (event) => {
        event.preventDefault();
        zoom = clamp(zoom * Math.exp(-event.deltaY * 0.0012), 0.58, 1.9);
        scheduleRender();
    }, { passive: false });

    root.querySelectorAll('[data-memory-network-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const action = button.dataset.memoryNetworkAction;

            if (action === 'rotate-left') rotationY -= 0.2;
            if (action === 'rotate-right') rotationY += 0.2;
            if (action === 'zoom-out') zoom = clamp(zoom - 0.12, 0.58, 1.9);
            if (action === 'zoom-in') zoom = clamp(zoom + 0.12, 0.58, 1.9);
            if (action === 'reset') {
                rotationX = reducedMotion ? -0.12 : -0.18;
                rotationY = reducedMotion ? 0.34 : 0.52;
                zoom = 1;
            }

            scheduleRender();
        });
    });

    scopeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            selectedScope = button.dataset.memoryNetworkScope || 'all';
            scopeButtons.forEach((candidate) => {
                candidate.setAttribute('aria-pressed', String(candidate === button));
            });
            applyFilters();
        });
    });

    searchInput?.addEventListener('input', () => {
        searchTerm = normalize(searchInput.value.trim());
        applyFilters();
    });

    listButtons.forEach((button, id) => {
        button.addEventListener('click', () => {
            const node = graph.memoryById.get(id);
            if (node) selectNode(node);
        });
    });

    const focusSearchWithSlash = (event) => {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        const target = event.target;
        if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target?.isContentEditable) {
            return;
        }

        event.preventDefault();
        searchInput?.focus();
    };
    document.addEventListener('keydown', focusSearchWithSlash);

    const resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(canvas.parentElement);
    const cleanup = () => {
        document.removeEventListener('keydown', focusSearchWithSlash);
        resizeObserver.disconnect();
        if (renderFrame !== null) window.cancelAnimationFrame(renderFrame);
        activeNetworkCleanups.delete(cleanup);
    };
    activeNetworkCleanups.add(cleanup);
    resize();
    applyFilters();
}

export function registerMemoryNetworks() {
    const register = () => document.querySelectorAll(NETWORK_SELECTOR).forEach(initializeNetwork);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', register, { once: true });
    } else {
        register();
    }

    document.addEventListener('livewire:navigating', () => {
        [...activeNetworkCleanups].forEach((cleanup) => cleanup());
    });
    document.addEventListener('livewire:navigated', register);
}

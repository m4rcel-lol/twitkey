(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const jsonFetch = async (url, options = {}) => {
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        headers.set('X-Requested-With', 'fetch');
        if (csrf) {
            headers.set('X-CSRF-Token', csrf);
        }
        const response = await fetch(url, { ...options, headers });
        const data = await response.json().catch(() => ({ ok: false, error: 'Invalid server response.' }));
        if (!response.ok || data.ok === false) {
            throw new Error(data.error || 'Request failed.');
        }
        return data;
    };

    const base64urlToBuffer = (value) => {
        const padded = `${value}${'='.repeat((4 - (value.length % 4)) % 4)}`;
        const binary = atob(padded.replace(/-/g, '+').replace(/_/g, '/'));
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    };

    const bufferToBase64url = (buffer) => {
        const bytes = new Uint8Array(buffer || new ArrayBuffer(0));
        let binary = '';
        bytes.forEach((byte) => {
            binary += String.fromCharCode(byte);
        });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    };

    const passkeyToPayload = (credential) => {
        const response = credential.response;
        const payload = {
            id: credential.id,
            type: credential.type,
            rawId: bufferToBase64url(credential.rawId),
            response: {
                clientDataJSON: bufferToBase64url(response.clientDataJSON),
            },
        };
        if (response.attestationObject) {
            payload.response.attestationObject = bufferToBase64url(response.attestationObject);
        }
        if (response.authenticatorData) {
            payload.response.authenticatorData = bufferToBase64url(response.authenticatorData);
        }
        if (response.signature) {
            payload.response.signature = bufferToBase64url(response.signature);
        }
        if (response.userHandle) {
            payload.response.userHandle = bufferToBase64url(response.userHandle);
        }
        return payload;
    };

    const creationOptions = (options) => ({
        ...options,
        challenge: base64urlToBuffer(options.challenge),
        user: { ...options.user, id: base64urlToBuffer(options.user.id) },
        excludeCredentials: (options.excludeCredentials || []).map((credential) => ({
            ...credential,
            id: base64urlToBuffer(credential.id),
        })),
    });

    const requestOptions = (options) => {
        const prepared = {
            ...options,
            challenge: base64urlToBuffer(options.challenge),
        };
        if (Array.isArray(options.allowCredentials)) {
            prepared.allowCredentials = options.allowCredentials.map((credential) => ({
                ...credential,
                id: base64urlToBuffer(credential.id),
            }));
        }
        return prepared;
    };

    let audioContext = null;
    const playNotificationSound = () => {
        try {
            audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gain.gain.setValueAtTime(0.0001, audioContext.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.08, audioContext.currentTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, audioContext.currentTime + 0.24);
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            oscillator.start();
            oscillator.stop(audioContext.currentTime + 0.26);
        } catch {
            // Browsers can block generated audio before the first user gesture.
        }
    };

    const formatCountLabel = (count, label) => {
        if (count <= 0) {
            return label;
        }
        return `(${count > 99 ? '99+' : count}) ${label}`;
    };

    const rotatePostId = (form) => {
        const input = form?.querySelector('[data-post-id]');
        if (!input) {
            return;
        }
        if (window.crypto?.randomUUID) {
            input.value = window.crypto.randomUUID();
            return;
        }
        input.value = `${Date.now().toString(16)}${Math.random().toString(16).slice(2)}`;
    };

    const maxTweetId = (root = document) => Math.max(
        0,
        ...Array.from(root.querySelectorAll('.tweet-row[data-tweet-id]')).map((row) => Number(row.dataset.tweetId || '0'))
    );

    const insertTweetHtml = (timeline, html, mode = 'prepend') => {
        if (!timeline || !html) {
            return 0;
        }
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const nodes = Array.from(template.content.children);
        const fragment = document.createDocumentFragment();
        const inserted = [];
        nodes.forEach((node) => {
            const tweetId = node.dataset?.tweetId || '';
            const safeTweetId = tweetId.replace(/[^0-9]/g, '');
            if (safeTweetId !== '' && timeline.querySelector(`.tweet-row[data-tweet-id="${safeTweetId}"]`)) {
                return;
            }
            fragment.appendChild(node);
            inserted.push(node);
        });
        if (inserted.length === 0) {
            return 0;
        }
        timeline.querySelector('.empty-state')?.remove();
        if (mode === 'append') {
            timeline.appendChild(fragment);
        } else {
            timeline.insertBefore(fragment, timeline.firstChild);
        }
        inserted.forEach((node) => wireDynamic(node));
        return inserted.length;
    };

    const setupCounter = (textarea) => {
        const target = document.querySelector(textarea.dataset.counterTarget || '');
        const form = textarea.closest('form');
        const button = form?.querySelector('button[type="submit"]');
        const update = () => {
            const remaining = 140 - Array.from(textarea.value).length;
            if (target) {
                target.textContent = String(remaining);
                target.classList.toggle('danger', remaining <= 20);
            }
            if (button) {
                button.disabled = remaining < 0;
            }
        };
        textarea.addEventListener('input', update);
        update();
    };

    document.querySelectorAll('textarea[data-counter-target]').forEach(setupCounter);

    const resetComposeExtras = (form) => {
        form.querySelectorAll('[data-compose-panel]').forEach((panel) => panel.classList.remove('open'));
        form.querySelectorAll('[data-panel-toggle]').forEach((button) => {
            button.classList.remove('active');
            if (button.dataset.defaultLabel) {
                button.lastChild.textContent = button.dataset.defaultLabel;
            }
        });
        const attachmentButton = form.querySelector('[data-attachment-button]');
        if (attachmentButton?.dataset.defaultLabel) {
            attachmentButton.lastChild.textContent = attachmentButton.dataset.defaultLabel;
        }
        form.querySelectorAll('[data-location-results]').forEach((target) => {
            target.textContent = '';
        });
        form.querySelectorAll('[data-location-lat], [data-location-lng], [data-location-label]').forEach((input) => {
            input.value = '';
        });
        const selectedLocation = form.querySelector('[data-selected-location]');
        if (selectedLocation) {
            selectedLocation.textContent = 'No location selected.';
        }
        const pin = form.querySelector('[data-map-pin]');
        if (pin) {
            pin.style.display = 'none';
        }
    };

    document.querySelectorAll('[data-tweet-form]').forEach((form) => {
        if (form.dataset.bound) {
            return;
        }
        form.dataset.bound = '1';
        form.addEventListener('submit', async (event) => {
            if (!window.fetch) {
                return;
            }
            event.preventDefault();
            if (form.dataset.submitting === '1') {
                return;
            }
            form.dataset.submitting = '1';
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
            }
            try {
                const data = await jsonFetch(form.action, { method: 'POST', body: new FormData(form) });
                const timeline = document.querySelector('#timeline');
                if (data.scheduled) {
                    alert('Post scheduled. It will appear when the selected time arrives.');
                } else if (timeline && data.html) {
                    insertTweetHtml(timeline, data.html, 'prepend');
                }
                form.reset();
                resetComposeExtras(form);
                rotatePostId(form);
                form.querySelectorAll('textarea[data-counter-target]').forEach((textarea) => textarea.dispatchEvent(new Event('input')));
            } catch (error) {
                alert(error.message);
            } finally {
                form.dataset.submitting = '0';
                if (button) {
                    button.disabled = false;
                }
            }
        });
    });

    const wirePoll = (root = document) => {
        root.querySelectorAll('[data-poll-form]').forEach((form) => {
            if (form.dataset.bound) {
                return;
            }
            form.dataset.bound = '1';
            form.addEventListener('submit', async (event) => {
                if (!window.fetch) {
                    return;
                }
                event.preventDefault();
                const row = form.closest('.tweet-row');
                const rowId = row?.id || '';
                try {
                    const data = await jsonFetch(form.action, { method: 'POST', body: new FormData(form) });
                    if (row && data.html) {
                        row.outerHTML = data.html;
                        const replacement = rowId ? document.getElementById(rowId) : null;
                        wireDynamic(replacement || document);
                    }
                } catch (error) {
                    alert(error.message);
                }
            });
        });
    };

    const wireAttachments = (root = document) => {
        root.querySelectorAll('[data-attachment-button]').forEach((button) => {
            if (button.dataset.bound) {
                return;
            }
            button.dataset.bound = '1';
            button.dataset.defaultLabel = button.lastChild.textContent;
            button.addEventListener('click', () => {
                button.closest('form')?.querySelector('[data-attachment-input]')?.click();
            });
        });

        root.querySelectorAll('[data-attachment-input]').forEach((input) => {
            if (input.dataset.bound) {
                return;
            }
            input.dataset.bound = '1';
            input.addEventListener('change', () => {
                const button = input.closest('form')?.querySelector('[data-attachment-button]');
                if (!button) {
                    return;
                }
                const count = input.files ? input.files.length : 0;
                button.lastChild.textContent = count > 0 ? `${count} file${count === 1 ? '' : 's'}` : (button.dataset.defaultLabel || 'Attachment');
            });
        });
    };

    const wireMediaLightbox = (root = document) => {
        const lightbox = document.querySelector('[data-media-lightbox]');
        const content = lightbox?.querySelector('[data-lightbox-content]');
        if (!lightbox || !content) {
            return;
        }
        root.querySelectorAll('[data-media-lightbox-item]').forEach((link) => {
            if (link.dataset.bound) {
                return;
            }
            link.dataset.bound = '1';
            link.addEventListener('click', (event) => {
                event.preventDefault();
                content.textContent = '';
                const url = link.getAttribute('href') || '';
                const type = link.dataset.mediaType || 'image';
                const node = type === 'video' ? document.createElement('video') : document.createElement('img');
                node.src = url;
                if (type === 'video') {
                    node.controls = true;
                    node.autoplay = true;
                } else {
                    node.alt = 'Expanded media';
                }
                content.appendChild(node);
                lightbox.hidden = false;
            });
        });
    };

    const wireFollow = (root = document) => {
        root.querySelectorAll('[data-follow-form]').forEach((form) => {
            if (form.dataset.bound) {
                return;
            }
            form.dataset.bound = '1';
            form.addEventListener('submit', async (event) => {
                if (!window.fetch) {
                    return;
                }
                event.preventDefault();
                const button = form.querySelector('button');
                const old = button.textContent;
                button.disabled = true;
                try {
                    const data = await jsonFetch(form.action, { method: 'POST', body: new FormData(form) });
                    button.textContent = data.pending ? 'Requested' : (data.following ? 'Unfollow' : 'Follow');
                } catch (error) {
                    button.textContent = old;
                    alert(error.message);
                } finally {
                    button.disabled = false;
                }
            });
        });
    };

    const wireFavorite = (root = document) => {
        root.querySelectorAll('[data-favorite-form]').forEach((form) => {
            if (form.dataset.bound) {
                return;
            }
            form.dataset.bound = '1';
            form.addEventListener('submit', async (event) => {
                if (!window.fetch) {
                    return;
                }
                event.preventDefault();
                const row = form.closest('.tweet-row');
                const button = form.querySelector('button');
                try {
                    const data = await jsonFetch(form.action, { method: 'POST', body: new FormData(form) });
                    button.textContent = data.favorited ? 'favorited' : 'favorite';
                    button.classList.toggle('is-favorited', Boolean(data.favorited));
                    const count = row?.querySelector('.favorite-count');
                    if (count) {
                        count.textContent = String(data.count);
                    }
                } catch (error) {
                    alert(error.message);
                }
            });
        });
    };

    const wireRetweet = (root = document) => {
        root.querySelectorAll('[data-retweet-form]').forEach((form) => {
            if (form.dataset.bound) {
                return;
            }
            form.dataset.bound = '1';
            form.addEventListener('submit', async (event) => {
                if (!window.fetch) {
                    return;
                }
                event.preventDefault();
                const row = form.closest('.tweet-row');
                const button = form.querySelector('button');
                if (button) {
                    button.disabled = true;
                }
                try {
                    const data = await jsonFetch(form.action, { method: 'POST', body: new FormData(form) });
                    if (button) {
                        button.textContent = 'reposted';
                        button.classList.add('is-retweeted');
                    }
                    const count = row?.querySelector('.retweet-count');
                    if (count) {
                        count.textContent = String(data.count);
                    }
                } catch (error) {
                    if (button) {
                        button.disabled = false;
                    }
                    alert(error.message);
                }
            });
        });
    };

    const wireReply = (root = document) => {
        root.querySelectorAll('[data-reply-toggle]').forEach((link) => {
            if (link.dataset.bound) {
                return;
            }
            link.dataset.bound = '1';
            link.addEventListener('click', (event) => {
                const row = link.closest('.tweet-row');
                const form = row?.querySelector('[data-reply-form]');
                if (!form) {
                    return;
                }
                event.preventDefault();
                form.classList.toggle('open');
                const textarea = form.querySelector('textarea');
                if (form.classList.contains('open') && textarea) {
                    textarea.focus();
                }
            });
        });

        root.querySelectorAll('[data-reply-form]').forEach((form) => {
            if (form.dataset.bound) {
                return;
            }
            form.dataset.bound = '1';
            form.addEventListener('submit', async (event) => {
                if (!window.fetch) {
                    return;
                }
                event.preventDefault();
                const button = form.querySelector('button[type="submit"]');
                const oldText = button ? button.textContent : '';
                if (button) {
                    button.disabled = true;
                    button.textContent = 'posting...';
                }
                try {
                    const data = await jsonFetch(form.action, { method: 'POST', body: new FormData(form) });
                    const row = form.closest('.tweet-row');
                    if (row && data.html) {
                        const repliesTimeline = document.querySelector('#replies-timeline');
                        if (repliesTimeline && row.closest('.tweet-detail')) {
                            insertTweetHtml(repliesTimeline, data.html, 'append');
                        } else {
                            row.insertAdjacentHTML('afterend', data.html);
                            wireDynamic(row.nextElementSibling);
                        }
                        const count = row.querySelector('.reply-count');
                        if (count) {
                            count.textContent = String(Number(count.textContent || '0') + 1);
                        }
                    }
                    form.reset();
                    rotatePostId(form);
                    form.classList.remove('open');
                } catch (error) {
                    alert(error.message);
                } finally {
                    if (button) {
                        button.disabled = false;
                        button.textContent = oldText;
                    }
                }
            });
        });
    };

    const wireDelete = (root = document) => {
        root.querySelectorAll('[data-delete-url]').forEach((button) => {
            if (button.dataset.bound) {
                return;
            }
            button.dataset.bound = '1';
            button.addEventListener('click', async () => {
                if (!confirm('Delete this tweet?')) {
                    return;
                }
                try {
                    await jsonFetch(button.dataset.deleteUrl, { method: 'DELETE' });
                    button.closest('.tweet-row')?.remove();
                } catch (error) {
                    alert(error.message);
                }
            });
        });
    };

    const wireConfirm = (root = document) => {
        root.querySelectorAll('[data-confirm]').forEach((button) => {
            if (button.dataset.bound) {
                return;
            }
            button.dataset.bound = '1';
            button.addEventListener('click', (event) => {
                if (!confirm(button.dataset.confirm)) {
                    event.preventDefault();
                }
            });
        });
    };

    const wireDynamic = (root = document) => {
        if (!root) {
            return;
        }
        wireFollow(root);
        wireFavorite(root);
        wireRetweet(root);
        wireReply(root);
        wireDelete(root);
        wireConfirm(root);
        wirePoll(root);
        wireAttachments(root);
        wireMediaLightbox(root);
    };

    wireDynamic(document);

    document.querySelectorAll('[data-panel-toggle]').forEach((button) => {
        button.dataset.defaultLabel = button.lastChild.textContent;
        button.addEventListener('click', () => {
            const form = button.closest('form');
            const name = button.dataset.panelToggle;
            const panel = form?.querySelector(`[data-compose-panel="${name}"]`);
            if (!form || !panel) {
                return;
            }
            const open = !panel.classList.contains('open');
            form.querySelectorAll('[data-compose-panel]').forEach((item) => item.classList.remove('open'));
            form.querySelectorAll('[data-panel-toggle]').forEach((toggle) => toggle.classList.remove('active'));
            panel.classList.toggle('open', open);
            button.classList.toggle('active', open);
        });
    });

    const mergeFilesIntoInput = (input, files) => {
        if (!input || !files || files.length === 0 || typeof DataTransfer === 'undefined') {
            return false;
        }
        const transfer = new DataTransfer();
        Array.from(input.files || []).forEach((file) => transfer.items.add(file));
        Array.from(files).forEach((file) => {
            if (file.type.startsWith('image/') || file.type.startsWith('video/') || file.type.startsWith('audio/')) {
                transfer.items.add(file);
            }
        });
        input.files = transfer.files;
        input.dispatchEvent(new Event('change'));
        return true;
    };

    document.addEventListener('paste', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLTextAreaElement)) {
            return;
        }
        const files = Array.from(event.clipboardData?.files || []).filter((file) => file.type.startsWith('image/'));
        if (files.length === 0) {
            return;
        }
        const input = target.closest('form')?.querySelector('[data-attachment-input]');
        if (mergeFilesIntoInput(input, files)) {
            event.preventDefault();
        }
    });

    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

    document.querySelectorAll('[data-account-menu]').forEach((menu) => {
        const toggle = menu.querySelector('[data-account-toggle]');
        const dropdown = menu.querySelector('[data-account-dropdown]');
        if (!toggle || !dropdown) {
            return;
        }
        const close = () => {
            dropdown.hidden = true;
            toggle.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        };
        toggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const opening = dropdown.hidden;
            document.querySelectorAll('[data-account-dropdown]').forEach((other) => {
                if (other !== dropdown) {
                    other.hidden = true;
                }
            });
            dropdown.hidden = !opening;
            toggle.classList.toggle('open', opening);
            toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
        });
        document.addEventListener('click', (event) => {
            if (!menu.contains(event.target)) {
                close();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                close();
            }
        });
    });

    const themeSelect = document.querySelector('[data-theme-select]');
    const customThemePanel = document.querySelector('[data-custom-theme-panel]');
    if (themeSelect && customThemePanel) {
        const syncCustomThemePanel = () => {
            customThemePanel.hidden = themeSelect.value !== 'custom';
        };
        themeSelect.addEventListener('change', syncCustomThemePanel);
        syncCustomThemePanel();
    }

    document.querySelectorAll('[data-crop-input]').forEach((input) => {
        const name = input.dataset.cropInput || '';
        const cropper = document.querySelector(`[data-cropper="${name}"]`);
        const stage = cropper?.querySelector('[data-crop-stage]');
        const image = cropper?.querySelector('[data-crop-image]');
        const box = cropper?.querySelector('[data-crop-box]');
        const zoom = cropper?.querySelector('[data-crop-zoom]');
        const aspect = Number(cropper?.dataset.cropAspect || '1') || 1;
        let crop = null;
        let drag = null;
        let objectUrl = '';

        if (!cropper || !stage || !image || !box) {
            return;
        }

        const setField = (key, value) => {
            const field = document.querySelector(`[data-crop-field="${name}:${key}"]`);
            if (field) {
                field.value = Number.isFinite(value) ? value.toFixed(6) : '';
            }
        };

        const imageRectInStage = () => {
            const stageRect = stage.getBoundingClientRect();
            const imageRect = image.getBoundingClientRect();
            return {
                left: imageRect.left - stageRect.left,
                top: imageRect.top - stageRect.top,
                width: imageRect.width,
                height: imageRect.height,
            };
        };

        const writeCropFields = () => {
            if (!crop) {
                ['x', 'y', 'w', 'h'].forEach((key) => setField(key, NaN));
                return;
            }
            const rect = imageRectInStage();
            setField('x', clamp((crop.x - rect.left) / Math.max(1, rect.width), 0, 1));
            setField('y', clamp((crop.y - rect.top) / Math.max(1, rect.height), 0, 1));
            setField('w', clamp(crop.w / Math.max(1, rect.width), 0, 1));
            setField('h', clamp(crop.h / Math.max(1, rect.height), 0, 1));
        };

        const renderCrop = () => {
            if (!crop) {
                return;
            }
            box.style.left = `${crop.x}px`;
            box.style.top = `${crop.y}px`;
            box.style.width = `${crop.w}px`;
            box.style.height = `${crop.h}px`;
            writeCropFields();
        };

        const createInitialCrop = (preserveCenter = false) => {
            const rect = imageRectInStage();
            if (rect.width <= 0 || rect.height <= 0) {
                return;
            }
            const previousCenter = crop && preserveCenter ? {
                x: crop.x + (crop.w / 2),
                y: crop.y + (crop.h / 2),
            } : null;
            const zoomScale = clamp(Number(zoom?.value || '100') / 100, 0.4, 1);
            let width = rect.width * zoomScale;
            let height = width / aspect;
            if (height > rect.height) {
                height = rect.height * zoomScale;
                width = height * aspect;
            }
            crop = {
                x: previousCenter ? previousCenter.x - (width / 2) : rect.left + ((rect.width - width) / 2),
                y: previousCenter ? previousCenter.y - (height / 2) : rect.top + ((rect.height - height) / 2),
                w: width,
                h: height,
            };
            crop.x = clamp(crop.x, rect.left, rect.left + rect.width - crop.w);
            crop.y = clamp(crop.y, rect.top, rect.top + rect.height - crop.h);
            renderCrop();
        };

        input.addEventListener('change', () => {
            const file = input.files?.[0] || null;
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = '';
            }
            crop = null;
            writeCropFields();
            if (!file || !file.type.startsWith('image/')) {
                cropper.hidden = true;
                image.removeAttribute('src');
                return;
            }
            objectUrl = URL.createObjectURL(file);
            image.onload = () => {
                cropper.hidden = false;
                if (zoom) {
                    zoom.value = '100';
                }
                requestAnimationFrame(() => createInitialCrop(false));
            };
            image.src = objectUrl;
        });

        zoom?.addEventListener('input', () => {
            if (!cropper.hidden && image.getAttribute('src')) {
                createInitialCrop(true);
            }
        });

        box.addEventListener('pointerdown', (event) => {
            if (!crop) {
                return;
            }
            event.preventDefault();
            if (box.setPointerCapture) {
                box.setPointerCapture(event.pointerId);
            }
            drag = {
                id: event.pointerId,
                startX: event.clientX,
                startY: event.clientY,
                cropX: crop.x,
                cropY: crop.y,
            };
        });

        box.addEventListener('pointermove', (event) => {
            if (!drag || !crop || drag.id !== event.pointerId) {
                return;
            }
            const rect = imageRectInStage();
            crop.x = clamp(drag.cropX + event.clientX - drag.startX, rect.left, rect.left + rect.width - crop.w);
            crop.y = clamp(drag.cropY + event.clientY - drag.startY, rect.top, rect.top + rect.height - crop.h);
            renderCrop();
        });

        const stopDrag = (event) => {
            if (drag && drag.id === event.pointerId) {
                drag = null;
            }
        };
        box.addEventListener('pointerup', stopDrag);
        box.addEventListener('pointercancel', stopDrag);
        window.addEventListener('resize', () => {
            if (!cropper.hidden && image.getAttribute('src')) {
                createInitialCrop(false);
            }
        });
    });

    const setLocation = (form, lat, lng, label) => {
        const latitude = clamp(Number(lat), -90, 90);
        const longitude = clamp(Number(lng), -180, 180);
        form.querySelector('[data-location-lat]').value = latitude.toFixed(6);
        form.querySelector('[data-location-lng]').value = longitude.toFixed(6);
        form.querySelector('[data-location-label]').value = label;
        const selected = form.querySelector('[data-selected-location]');
        if (selected) {
            selected.textContent = label;
        }
        const pin = form.querySelector('[data-map-pin]');
        if (pin) {
            pin.style.display = 'block';
            pin.style.left = `${((longitude + 180) / 360) * 100}%`;
            pin.style.top = `${((90 - latitude) / 180) * 100}%`;
        }
    };

    document.querySelectorAll('[data-location-search]').forEach((button) => {
        button.addEventListener('click', async () => {
            const form = button.closest('form');
            const input = form?.querySelector('[data-location-query]');
            const results = form?.querySelector('[data-location-results]');
            const query = input?.value.trim() || '';
            if (!form || !input || !results || query === '') {
                return;
            }
            results.textContent = 'Searching...';
            try {
                const data = await jsonFetch(`/api/locations?q=${encodeURIComponent(query)}`);
                results.textContent = '';
                if (!data.items || data.items.length === 0) {
                    results.textContent = 'No places found.';
                    return;
                }
                data.items.forEach((item) => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.textContent = item.label;
                    option.addEventListener('click', () => setLocation(form, item.lat, item.lng, item.label));
                    results.appendChild(option);
                });
            } catch (error) {
                results.textContent = error.message;
            }
        });
    });

    document.querySelectorAll('[data-map-picker]').forEach((picker) => {
        picker.addEventListener('click', (event) => {
            const form = picker.closest('form');
            if (!form) {
                return;
            }
            const rect = picker.getBoundingClientRect();
            const x = clamp((event.clientX - rect.left) / rect.width, 0, 1);
            const y = clamp((event.clientY - rect.top) / rect.height, 0, 1);
            const lat = 90 - (y * 180);
            const lng = (x * 360) - 180;
            setLocation(form, lat, lng, `${lat.toFixed(4)}, ${lng.toFixed(4)}`);
        });
    });

    document.querySelectorAll('[data-note-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const note = button.closest('[data-community-note]');
            const collapsed = note.classList.toggle('is-collapsed');
            button.textContent = collapsed ? '▶ show' : '▼ hide';
        });
    });

    const dmThread = document.querySelector('[data-dm-thread]');
    if (dmThread) {
        dmThread.scrollTop = dmThread.scrollHeight;
    }

    const wireDirectMessages = () => {
        const thread = document.querySelector('[data-dm-thread][data-dm-user]');
        const form = document.querySelector('[data-dm-form]');
        const countNode = document.querySelector('[data-dm-message-count]');
        const lastMessageId = () => Math.max(0, ...Array.from(thread?.querySelectorAll('[data-message-id]') || []).map((row) => Number(row.dataset.messageId || '0')));
        const appendMessages = (html, count) => {
            if (!thread || !html) {
                return;
            }
            thread.insertAdjacentHTML('beforeend', html);
            wireDynamic(thread);
            thread.scrollTop = thread.scrollHeight;
            if (countNode && Number.isFinite(Number(count))) {
                countNode.textContent = String(count);
            }
        };

        if (form && thread) {
            form.addEventListener('submit', async (event) => {
                if (!window.fetch) {
                    return;
                }
                event.preventDefault();
                const button = form.querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = true;
                }
                try {
                    const data = await jsonFetch(form.action, { method: 'POST', body: new FormData(form) });
                    appendMessages(data.html, data.count);
                    form.reset();
                } catch (error) {
                    alert(error.message);
                } finally {
                    if (button) {
                        button.disabled = false;
                    }
                }
            });
        }

        if (thread) {
            const pollMessages = async () => {
                if (document.hidden) {
                    return;
                }
                const before = lastMessageId();
                try {
                    const data = await jsonFetch(`/api/messages?user=${encodeURIComponent(thread.dataset.dmUser)}&since_id=${before}`);
                    appendMessages(data.html, data.count);
                    if (data.html && lastMessageId() > before) {
                        playNotificationSound();
                    }
                } catch {
                    // Ignore transient polling failures.
                }
            };
            setInterval(pollMessages, 3000);
        }
    };
    wireDirectMessages();

    const wireRealtimeFeeds = () => {
        document.querySelectorAll('[data-realtime-feed]').forEach((timeline) => {
            const insertMode = timeline.dataset.realtimeInsert || 'prepend';
            const pollFeed = async () => {
                if (document.hidden) {
                    return;
                }
                try {
                    const since = maxTweetId(timeline);
                    const separator = timeline.dataset.realtimeFeed.includes('?') ? '&' : '?';
                    const data = await jsonFetch(`${timeline.dataset.realtimeFeed}${separator}since_id=${since}`);
                    if (!data.html) {
                        return;
                    }
                    insertTweetHtml(timeline, data.html, insertMode);
                } catch {
                    // Ignore transient polling failures.
                }
            };
            setInterval(pollFeed, 5000);
        });
    };
    wireRealtimeFeeds();

    const wireRealtimeCounters = () => {
        const notificationsLink = document.querySelector('[data-notifications-link]');
        const messagesLink = document.querySelector('[data-messages-link]');
        if (!notificationsLink && !messagesLink) {
            return;
        }
        let lastNotifications = Number(notificationsLink?.dataset.count || '0');
        let lastMessages = Number(messagesLink?.dataset.count || '0');
        const pollCounters = async () => {
            try {
                const data = await jsonFetch('/api/realtime');
                const notifications = Number(data.notifications || 0);
                const messages = Number(data.messages || 0);
                if (notificationsLink) {
                    notificationsLink.textContent = formatCountLabel(notifications, 'Notifications');
                    notificationsLink.dataset.count = String(notifications);
                }
                if (messagesLink) {
                    messagesLink.textContent = formatCountLabel(messages, 'Direct Messages');
                    messagesLink.dataset.count = String(messages);
                }
                if (notifications > lastNotifications || messages > lastMessages) {
                    playNotificationSound();
                }
                lastNotifications = notifications;
                lastMessages = messages;
            } catch {
                // Ignore transient polling failures.
            }
        };
        setInterval(pollCounters, 5000);
    };
    wireRealtimeCounters();

    const wireRealtimePolls = () => {
        const refreshPollRows = async () => {
            if (document.hidden) {
                return;
            }
            const ids = Array.from(document.querySelectorAll('.tweet-row[data-tweet-id]'))
                .filter((row) => row.querySelector('[data-poll-form]'))
                .map((row) => row.dataset.tweetId)
                .filter(Boolean);
            if (ids.length === 0) {
                return;
            }
            try {
                const data = await jsonFetch(`/api/polls?tweet_ids=${encodeURIComponent([...new Set(ids)].join(','))}`);
                Object.entries(data.rows || {}).forEach(([id, html]) => {
                    const row = document.querySelector(`.tweet-row[data-tweet-id="${id.replace(/[^0-9]/g, '')}"]`);
                    if (row && html) {
                        row.outerHTML = html;
                    }
                });
                wireDynamic(document);
            } catch {
                // Ignore transient polling failures.
            }
        };
        setInterval(refreshPollRows, 8000);
    };
    wireRealtimePolls();

    const siteAlert = document.querySelector('[data-site-alert]');
    if (siteAlert) {
        const message = siteAlert.querySelector('[data-site-alert-message]');
        const refreshAlert = async () => {
            try {
                const data = await jsonFetch('/api/site_alert');
                const alert = data.alert;
                if (alert && alert.message) {
                    siteAlert.dataset.alertId = String(alert.id || '0');
                    if (message) {
                        message.textContent = alert.message;
                    }
                    siteAlert.classList.remove('hidden');
                } else {
                    siteAlert.dataset.alertId = '0';
                    if (message) {
                        message.textContent = '';
                    }
                    siteAlert.classList.add('hidden');
                }
            } catch {
                // Ignore transient polling failures; the next poll will retry.
            }
        };
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                refreshAlert();
            }
        });
        setInterval(refreshAlert, 10000);
    }

    document.querySelector('[data-lightbox-close]')?.addEventListener('click', () => {
        const lightbox = document.querySelector('[data-media-lightbox]');
        const content = lightbox?.querySelector('[data-lightbox-content]');
        if (content) {
            content.textContent = '';
        }
        if (lightbox) {
            lightbox.hidden = true;
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelector('[data-lightbox-close]')?.dispatchEvent(new Event('click'));
        }
        const inspectShortcut = event.key === 'F12'
            || ((event.ctrlKey || event.metaKey) && event.shiftKey && ['I', 'J', 'C'].includes(event.key.toUpperCase()))
            || ((event.ctrlKey || event.metaKey) && event.key.toUpperCase() === 'U');
        if (inspectShortcut) {
            event.preventDefault();
        }
    });

    document.addEventListener('contextmenu', (event) => {
        if (!event.target.closest('input, textarea')) {
            event.preventDefault();
        }
    });

    const usernameInput = document.querySelector('[data-username-check]');
    if (usernameInput) {
        let timer = null;
        const status = document.querySelector('.username-status');
        usernameInput.addEventListener('input', () => {
            clearTimeout(timer);
            const value = usernameInput.value.trim();
            status.textContent = '';
            status.className = 'field-error username-status';
            if (!value) {
                return;
            }
            timer = setTimeout(async () => {
                try {
                    const data = await jsonFetch(`/api/username?username=${encodeURIComponent(value)}`);
                    status.textContent = data.available ? '✓ username available' : '✗ username unavailable';
                    status.classList.add(data.available ? 'ok' : 'bad');
                } catch {
                    status.textContent = 'Could not check username.';
                    status.classList.add('bad');
                }
            }, 500);
        });
    }

    const passkeyRegister = document.querySelector('[data-passkey-register]');
    if (passkeyRegister) {
        const status = document.querySelector('[data-passkey-register-status]');
        passkeyRegister.addEventListener('click', async () => {
            if (!window.PublicKeyCredential) {
                status.textContent = 'This browser does not support passkeys.';
                return;
            }
            passkeyRegister.disabled = true;
            status.textContent = 'Waiting for your browser...';
            try {
                const name = window.prompt('Name this passkey', 'Passkey') || 'Passkey';
                const data = await jsonFetch('/settings/2fa/passkeys/options');
                const credential = await navigator.credentials.create({ publicKey: creationOptions(data.options) });
                const payload = passkeyToPayload(credential);
                payload.passkey_name = name;
                await jsonFetch('/settings/2fa/passkeys/register', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                status.textContent = 'Passkey added.';
                window.location.reload();
            } catch (error) {
                status.textContent = error.message || 'Passkey registration failed.';
                passkeyRegister.disabled = false;
            }
        });
    }

    const passkeyLogin = document.querySelector('[data-passkey-login]');
    if (passkeyLogin) {
        const status = document.querySelector('[data-passkey-login-status]');
        passkeyLogin.addEventListener('click', async () => {
            if (!window.PublicKeyCredential) {
                status.textContent = 'This browser does not support passkeys.';
                return;
            }
            passkeyLogin.disabled = true;
            status.textContent = 'Waiting for your passkey...';
            try {
                const passwordless = passkeyLogin.dataset.passkeyLoginMode === 'passwordless';
                const data = await jsonFetch(passwordless ? '/login/passkey/options' : '/login/2fa/passkey/options');
                const getOptions = { publicKey: requestOptions(data.options) };
                if (passwordless) {
                    getOptions.mediation = 'optional';
                }
                const credential = await navigator.credentials.get(getOptions);
                const result = await jsonFetch(passwordless ? '/login/passkey/verify' : '/login/2fa/passkey/verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(passkeyToPayload(credential)),
                });
                window.location.href = result.redirect || '/';
            } catch (error) {
                status.textContent = error.message || 'Passkey verification failed.';
                passkeyLogin.disabled = false;
            }
        });
    }

    const adminPasskeyVerify = document.querySelector('[data-admin-passkey-verify]');
    if (adminPasskeyVerify) {
        const status = document.querySelector('[data-admin-passkey-status]');
        adminPasskeyVerify.addEventListener('click', async () => {
            if (!window.PublicKeyCredential) {
                status.textContent = 'This browser does not support passkeys.';
                return;
            }
            adminPasskeyVerify.disabled = true;
            status.textContent = 'Waiting for your passkey...';
            try {
                const data = await jsonFetch('/admin/verify/passkey/options');
                const credential = await navigator.credentials.get({ publicKey: requestOptions(data.options) });
                const result = await jsonFetch('/admin/verify/passkey', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(passkeyToPayload(credential)),
                });
                window.location.href = result.redirect || '/admin';
            } catch (error) {
                status.textContent = error.message || 'Passkey verification failed.';
                adminPasskeyVerify.disabled = false;
            }
        });
    }

    document.querySelectorAll('textarea').forEach((textarea) => {
        let box = null;
        const closeBox = () => {
            box?.remove();
            box = null;
        };
        textarea.addEventListener('input', async () => {
            const cursor = textarea.selectionStart || 0;
            const prefix = textarea.value.slice(0, cursor);
            const match = prefix.match(/(^|\s)([@#])([A-Za-z0-9_]{1,20})$/);
            if (!match) {
                closeBox();
                return;
            }
            const type = match[2];
            const query = match[3];
            try {
                const data = await jsonFetch(`/api/suggest?type=${encodeURIComponent(type)}&q=${encodeURIComponent(query)}`);
                closeBox();
                if (!data.items || data.items.length === 0) {
                    return;
                }
                box = document.createElement('div');
                box.className = 'autocomplete-box';
                data.items.forEach((item) => {
                    const value = item.value || item.tag;
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.textContent = type + value;
                    option.addEventListener('click', () => {
                        const start = cursor - query.length - 1;
                        textarea.value = textarea.value.slice(0, start) + type + value + ' ' + textarea.value.slice(cursor);
                        textarea.focus();
                        closeBox();
                        textarea.dispatchEvent(new Event('input'));
                    });
                    box.appendChild(option);
                });
                const rect = textarea.getBoundingClientRect();
                box.style.left = `${rect.left + window.scrollX}px`;
                box.style.top = `${rect.bottom + window.scrollY}px`;
                document.body.appendChild(box);
            } catch {
                closeBox();
            }
        });
        textarea.addEventListener('blur', () => setTimeout(closeBox, 150));
    });
})();

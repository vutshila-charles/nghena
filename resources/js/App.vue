<script setup>
import axios from 'axios';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';

const api = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL || '',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

const user = ref(null);
const appReady = ref(false);
const email = ref('');
const password = ref('');
const rememberMe = ref(true);
const passwordVisible = ref(false);
const busy = ref(false);
const message = ref('');
const error = ref('');
const current = ref(null);
const history = ref([]);
const workplaces = ref([]);
const selectedWorkplace = ref('');
const capturedImage = ref(null);
const video = ref(null);
const cameraStream = ref(null);
const faceSetupOpen = ref(false);
const faceSetupStep = ref('intro');
const enrollmentVideo = ref(null);
const enrollmentStream = ref(null);
const enrollmentImage = ref(null);
const enrollmentPreviewUrl = ref('');
const faceConsent = ref(false);
const actionProgress = ref('');
const isAdmin = computed(() => user.value?.role === 'admin');
const nextAttendanceAction = computed(() => {
    if (!current.value) return { action: 'clock-in', label: 'Clock in' };
    if (current.value.status === 'on_break') return { action: 'break/end', label: 'Finish break' };
    if (current.value.status === 'clocked_in') return { action: 'clock-out', label: 'Clock out' };

    return null;
});
const admin = ref({ dashboard: null, employees: [], corrections: [], workplaces: [], shifts: [] });
const newEmployee = ref({ name: '', email: '', password: '' });
const newWorkplace = ref({ name: '', address: '', latitude: '', longitude: '', radius_meters: 150 });
const newShift = ref({ name: '', starts_at: '09:00', ends_at: '17:00', break_minutes: 30 });
const assignment = ref({ user_id: '', workplace_id: '', shift_id: '' });
const correctionForm = ref({ attendance_record_id: '', workplace_id: '', work_date: '', clocked_in_at: '', clocked_out_at: '', reason: '' });

function showError(exception) {
    const body = exception?.response?.data;
    const messages = {
        face_provider_unavailable: 'Face verification is not configured yet. Please ask your administrator to set it up.',
        face_enrollment_required: 'Set up your face check before clocking in.',
        face_verification_failed: 'We could not confirm your face. Please try again in good light.',
        workplace_not_assigned: 'You do not have a workplace assigned yet. Please ask your manager.',
        outside_workplace_radius: 'You seem to be too far from your assigned workplace.',
        unauthenticated: 'Your session has ended. Please sign in again.',
    };
    error.value = messages[body?.error?.code] || body?.error?.message || body?.message || 'Something went wrong. Please try again.';
}

async function loadApp() {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/api/v1/auth/me');
        user.value = data.data.user;
        await refreshData();
    } catch (exception) {
        if (exception.response?.status !== 401) showError(exception);
    } finally {
        busy.value = false;
        appReady.value = true;
    }
}

async function refreshData() {
    const [status, attendance, assigned] = await Promise.all([
        api.get('/api/v1/attendance/status'),
        api.get('/api/v1/attendance/history'),
        api.get('/api/v1/workplaces/assigned'),
    ]);
    current.value = status.data.data.attendance;
    history.value = attendance.data.data || [];
    workplaces.value = assigned.data.data.map((assignment) => assignment.workplace);
    if (!selectedWorkplace.value && workplaces.value.length) selectedWorkplace.value = String(workplaces.value[0].id);
    if (isAdmin.value) {
        const [dashboard, employees, corrections, adminWorkplaces, shifts] = await Promise.all([
            api.get('/api/v1/admin/dashboard'),
            api.get('/api/v1/admin/employees'),
            api.get('/api/v1/admin/corrections?status=pending'),
            api.get('/api/v1/admin/workplaces'),
            api.get('/api/v1/admin/shifts'),
        ]);
        admin.value = {
            dashboard: dashboard.data.data,
            employees: employees.data.data,
            corrections: corrections.data.data,
            workplaces: adminWorkplaces.data.data,
            shifts: shifts.data.data,
        };
    }
}

async function login() {
    busy.value = true;
    error.value = '';
    try {
        await api.get('/sanctum/csrf-cookie');
        const { data } = await api.post('/api/v1/auth/login', {
            email: email.value,
            password: password.value,
            remember: rememberMe.value,
        });
        user.value = data.data.user;
        password.value = '';
        await refreshData();
    } catch (exception) {
        showError(exception);
    } finally {
        busy.value = false;
    }
}

async function logout() {
    try {
        await api.post('/api/v1/auth/logout');
    } finally {
        user.value = null;
        current.value = null;
        history.value = [];
    }
}

function stopCamera() {
    cameraStream.value?.getTracks().forEach((track) => track.stop());
    cameraStream.value = null;
}

async function capturePhoto(stream = cameraStream.value) {
    await nextTick();
    const preview = video.value;

    if (!preview || !stream) {
        throw new Error('The camera could not start. Check camera permission and try again.');
    }

    preview.srcObject = stream;
    await preview.play();
    if (!preview.videoWidth) {
        await new Promise((resolve, reject) => {
            preview.addEventListener('loadedmetadata', resolve, { once: true });
            preview.addEventListener('error', reject, { once: true });
        });
    }

    actionProgress.value = 'Look at the camera. Taking your photo…';
    await new Promise((resolve) => window.setTimeout(resolve, 900));
    const canvas = document.createElement('canvas');
    canvas.width = preview.videoWidth;
    canvas.height = preview.videoHeight;
    canvas.getContext('2d').drawImage(preview, 0, 0);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));

    if (!blob) {
        throw new Error('The photo could not be taken. Please try again.');
    }

    capturedImage.value = new File([blob], 'face-capture.jpg', { type: 'image/jpeg' });
    if (stream === cameraStream.value) {
        message.value = 'Photo captured. It will be sent securely for server-side verification.';
    }
}

function getLocation() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('Location is not available in this browser.'));
            return;
        }
        navigator.geolocation.getCurrentPosition(
            ({ coords }) => resolve({ latitude: coords.latitude, longitude: coords.longitude }),
            () => reject(new Error('Location permission is required for attendance actions.')),
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 },
        );
    });
}

async function submitAttendance(action = nextAttendanceAction.value?.action) {
    if (!action) return;
    if (action === 'clock-in' && !selectedWorkplace.value) {
        error.value = 'You do not have a workplace assigned yet. Please ask your manager.';
        return;
    }

    busy.value = true;
    error.value = '';
    message.value = '';
    actionProgress.value = 'Checking your location…';
    let stream;
    try {
        if (!user.value.face_enrolled) {
            error.value = 'Ask your administrator to finish setting up face verification before your first clock-in.';
            return;
        }

        const [position, camera] = await Promise.all([
            getLocation(),
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }),
        ]);
        stream = camera;
        cameraStream.value = camera;
        const form = new FormData();
        Object.entries(position).forEach(([key, value]) => form.append(key, value));
        actionProgress.value = '';
        await capturePhoto(camera);
        actionProgress.value = 'Verifying and saving…';
        form.append('face_image', capturedImage.value);
        if (action === 'clock-in') form.append('workplace_id', selectedWorkplace.value);
        await api.post(`/api/v1/attendance/${action}`, form, {
            headers: { 'Idempotency-Key': crypto.randomUUID() },
        });
        capturedImage.value = null;
        message.value = action === 'clock-in'
            ? 'You’re clocked in. Have a great shift!'
            : action === 'clock-out'
                ? 'You’re clocked out. Have a great day!'
                : 'Your break is finished.';
        await refreshData();
    } catch (exception) {
        if (exception.code === 1) error.value = 'Please allow location access, then try again.';
        else if (exception.name === 'NotAllowedError') error.value = 'Please allow camera access, then tap the button to try again.';
        else if (exception.message && !exception.response) error.value = exception.message;
        else showError(exception);
    } finally {
        stream?.getTracks().forEach((track) => track.stop());
        cameraStream.value = null;
        actionProgress.value = '';
        busy.value = false;
    }
}

function openFaceSetup() {
    error.value = '';
    message.value = '';
    faceConsent.value = false;
    faceSetupStep.value = 'intro';
    faceSetupOpen.value = true;
}

function stopEnrollmentCamera() {
    enrollmentStream.value?.getTracks().forEach((track) => track.stop());
    enrollmentStream.value = null;
}

function clearEnrollmentPhoto() {
    if (enrollmentPreviewUrl.value) URL.revokeObjectURL(enrollmentPreviewUrl.value);
    enrollmentPreviewUrl.value = '';
    enrollmentImage.value = null;
}

function closeFaceSetup() {
    stopEnrollmentCamera();
    clearEnrollmentPhoto();
    faceSetupStep.value = 'intro';
    faceSetupOpen.value = false;
    error.value = '';
}

async function startEnrollmentCamera() {
    busy.value = true;
    error.value = '';
    try {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('Camera access is not available in this browser.');
        }

        enrollmentStream.value = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user' },
            audio: false,
        });
        faceSetupStep.value = 'capture';
        await nextTick();
        enrollmentVideo.value.srcObject = enrollmentStream.value;
        await enrollmentVideo.value.play();
    } catch (exception) {
        stopEnrollmentCamera();
        faceSetupStep.value = 'intro';
        if (exception.name === 'NotAllowedError') error.value = 'Allow camera access in your browser settings, then try again.';
        else if (exception.name === 'NotFoundError') error.value = 'No camera was found on this device.';
        else if (exception.message && !exception.response) error.value = exception.message;
        else showError(exception);
    } finally {
        busy.value = false;
    }
}

async function captureEnrollmentPhoto() {
    busy.value = true;
    try {
        const preview = enrollmentVideo.value;
        if (!preview || !enrollmentStream.value || !preview.videoWidth) {
            error.value = 'The camera is still starting. Please wait a moment and try again.';
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = preview.videoWidth;
        canvas.height = preview.videoHeight;
        const context = canvas.getContext('2d');
        if (!context) {
            error.value = 'The photo could not be prepared. Please try again.';
            return;
        }

        context.drawImage(preview, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
        if (!blob) {
            error.value = 'The photo could not be captured. Please try again.';
            return;
        }

        clearEnrollmentPhoto();
        enrollmentImage.value = new File([blob], 'face-enrollment.jpg', { type: 'image/jpeg' });
        enrollmentPreviewUrl.value = URL.createObjectURL(enrollmentImage.value);
        stopEnrollmentCamera();
        faceSetupStep.value = 'review';
        error.value = '';
    } catch (exception) {
        error.value = exception.message || 'The photo could not be captured. Please try again.';
    } finally {
        busy.value = false;
    }
}

async function submitFaceEnrollment() {
    if (!enrollmentImage.value) return;

    busy.value = true;
    error.value = '';
    try {
        const form = new FormData();
        form.append('image', enrollmentImage.value);
        await api.post('/api/v1/face/enrollment', form);
        closeFaceSetup();
        message.value = 'Face check is ready. You can clock in now.';
        await loadApp();
    } catch (exception) {
        showError(exception);
    } finally {
        busy.value = false;
    }
}

async function retakeEnrollmentPhoto() {
    clearEnrollmentPhoto();
    await startEnrollmentCamera();
}

async function createEmployee() {
    busy.value = true;
    try {
        await api.post('/api/v1/admin/employees', newEmployee.value);
        newEmployee.value = { name: '', email: '', password: '' };
        message.value = 'Employee created.';
        await refreshData();
    } catch (exception) {
        showError(exception);
    } finally {
        busy.value = false;
    }
}

async function createWorkplace() {
    busy.value = true;
    try {
        await api.post('/api/v1/admin/workplaces', {
            ...newWorkplace.value,
            latitude: Number(newWorkplace.value.latitude),
            longitude: Number(newWorkplace.value.longitude),
            radius_meters: Number(newWorkplace.value.radius_meters),
        });
        newWorkplace.value = { name: '', address: '', latitude: '', longitude: '', radius_meters: 150 };
        message.value = 'Workplace created.';
        await refreshData();
    } catch (exception) {
        showError(exception);
    } finally {
        busy.value = false;
    }
}

async function assignWorkplace() {
    busy.value = true;
    try {
        await api.post(`/api/v1/admin/employees/${assignment.value.user_id}/workplaces`, {
            workplace_id: Number(assignment.value.workplace_id),
            shift_id: assignment.value.shift_id ? Number(assignment.value.shift_id) : null,
        });
        assignment.value = { user_id: '', workplace_id: '', shift_id: '' };
        message.value = 'Workplace assignment saved.';
        await refreshData();
    } catch (exception) {
        showError(exception);
    } finally {
        busy.value = false;
    }
}

async function createShift() {
    busy.value = true;
    try {
        await api.post('/api/v1/admin/shifts', {
            ...newShift.value,
            break_minutes: Number(newShift.value.break_minutes),
        });
        newShift.value = { name: '', starts_at: '09:00', ends_at: '17:00', break_minutes: 30 };
        message.value = 'Shift created.';
        await refreshData();
    } catch (exception) {
        showError(exception);
    } finally {
        busy.value = false;
    }
}

async function requestCorrection() {
    busy.value = true;
    try {
        const payload = {
            work_date: correctionForm.value.work_date,
            reason: correctionForm.value.reason,
            ...(correctionForm.value.attendance_record_id
                ? { attendance_record_id: Number(correctionForm.value.attendance_record_id) }
                : { workplace_id: Number(correctionForm.value.workplace_id) }),
        };
        if (correctionForm.value.clocked_in_at) payload.clocked_in_at = new Date(correctionForm.value.clocked_in_at).toISOString();
        if (correctionForm.value.clocked_out_at) payload.clocked_out_at = new Date(correctionForm.value.clocked_out_at).toISOString();
        await api.post('/api/v1/attendance/corrections', payload);
        correctionForm.value = { attendance_record_id: '', workplace_id: '', work_date: '', clocked_in_at: '', clocked_out_at: '', reason: '' };
        message.value = 'Correction request submitted for review.';
    } catch (exception) {
        showError(exception);
    } finally {
        busy.value = false;
    }
}

async function reviewCorrection(id, decision) {
    try {
        await api.patch(`/api/v1/admin/corrections/${id}`, { decision });
        message.value = `Correction ${decision}.`;
        await refreshData();
    } catch (exception) {
        showError(exception);
    }
}

function formatTime(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date(value));
}

onMounted(loadApp);
onUnmounted(() => {
    stopCamera();
    closeFaceSetup();
});
</script>

<template>
    <div class="min-h-screen">
        <header v-if="user" class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4">
                <a class="flex items-center gap-3 font-semibold tracking-tight" href="/">
                    <span class="grid size-10 place-items-center rounded-xl bg-indigo-600 text-lg font-bold text-white">N</span>
                    <span class="text-xl">Nghena<span class="text-indigo-600">.</span></span>
                </a>
                <div v-if="user" class="flex items-center gap-3">
                    <div class="hidden text-right sm:block">
                        <div class="text-sm font-semibold">{{ user.name }}</div>
                        <div class="text-xs capitalize text-slate-500">{{ user.role }}</div>
                    </div>
                    <button class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium hover:bg-slate-50" @click="logout">Sign out</button>
                </div>
            </div>
        </header>

        <main :class="user ? 'mx-auto max-w-7xl px-5 py-5 sm:py-9' : 'flex min-h-screen items-center justify-center bg-gradient-to-b from-indigo-50 via-slate-50 to-white px-4 py-10 sm:px-6'">
            <section v-if="!user && appReady" class="w-full max-w-md">
                <div class="mb-8 flex items-center justify-center gap-3">
                    <span class="grid size-12 place-items-center rounded-2xl bg-indigo-600 text-xl font-bold text-white shadow-lg shadow-indigo-200">N</span>
                    <span class="text-2xl font-bold tracking-tight text-slate-900">Nghena<span class="text-indigo-600">.</span></span>
                </div>
                <form class="rounded-3xl bg-white p-6 shadow-xl shadow-slate-200/70 ring-1 ring-slate-200 sm:p-9" @submit.prevent="login">
                    <div>
                        <p class="text-sm font-semibold text-indigo-600">WELCOME BACK</p>
                        <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Sign in</h1>
                        <p class="mt-2 text-slate-600">Your workday starts here.</p>
                    </div>
                    <label class="mt-8 block text-sm font-semibold text-slate-800">Work email
                        <input v-model="email" type="email" autocomplete="username" inputmode="email" required class="mt-2 min-h-14 w-full rounded-2xl border border-slate-300 bg-white px-4 text-base outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100">
                    </label>
                    <label class="mt-5 block text-sm font-semibold text-slate-800">Password
                        <span class="relative mt-2 block">
                            <input v-model="password" :type="passwordVisible ? 'text' : 'password'" autocomplete="current-password" required class="min-h-14 w-full rounded-2xl border border-slate-300 bg-white px-4 pr-20 text-base outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100">
                            <button type="button" class="absolute inset-y-0 right-0 min-w-16 px-4 text-sm font-semibold text-indigo-700" :aria-label="passwordVisible ? 'Hide password' : 'Show password'" @click="passwordVisible = !passwordVisible">
                                {{ passwordVisible ? 'Hide' : 'Show' }}
                            </button>
                        </span>
                    </label>
                    <label class="mt-5 flex min-h-11 cursor-pointer items-center gap-3 text-sm text-slate-700">
                        <input v-model="rememberMe" type="checkbox" class="size-5 rounded border-slate-300 accent-indigo-600">
                        <span>Keep me signed in on this device</span>
                    </label>
                    <p v-if="error" role="alert" class="mt-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
                    <button :disabled="busy" class="mt-6 min-h-14 w-full rounded-2xl bg-indigo-600 px-4 text-base font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60">
                        {{ busy ? 'Signing in…' : 'Sign in' }}
                    </button>
                </form>
                <p class="mx-auto mt-6 max-w-xs text-center text-xs leading-5 text-slate-500">Your sign-in is protected with secure cookies. You can sign out anytime from your profile.</p>
            </section>

            <section v-else-if="!user" class="text-center" role="status" aria-live="polite">
                <span class="mx-auto grid size-12 place-items-center rounded-2xl bg-indigo-600 text-xl font-bold text-white">N</span>
                <p class="mt-4 text-sm font-medium text-slate-600">Opening Nghena…</p>
            </section>

            <template v-else>
                <section v-if="!isAdmin" class="mx-auto max-w-xl">
                    <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-7">
                        <div class="text-center">
                            <h1 class="text-2xl font-semibold">
                                {{ current?.status === 'completed' ? 'You’re all done today' : current?.status === 'on_break' ? 'You’re on a break' : current ? 'You’re clocked in' : `Ready to clock in, ${user.name.split(' ')[0]}?` }}
                            </h1>
                            <p v-if="current?.clocked_in_at" class="mt-1 text-slate-600">
                                Clocked in at <strong class="font-semibold text-slate-900">{{ formatTime(current.clocked_in_at) }}</strong>
                            </p>
                            <p v-else-if="current?.status === 'completed'" class="mt-1 text-slate-600">
                                You clocked out at <strong class="font-semibold text-slate-900">{{ formatTime(current.clocked_out_at) }}</strong>
                            </p>
                        </div>

                        <div v-if="!current && workplaces.length > 1" class="mt-6">
                            <label for="employee-workplace" class="block text-sm font-medium">Where are you working today?</label>
                            <select id="employee-workplace" v-model="selectedWorkplace" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-100">
                                <option v-for="place in workplaces" :key="place.id" :value="String(place.id)">{{ place.name }}</option>
                            </select>
                        </div>
                        <p v-else-if="!current && workplaces.length === 1" class="mt-3 text-center text-sm text-slate-600">
                            Your workplace: <strong class="font-semibold text-slate-900">{{ workplaces[0].name }}</strong>
                        </p>
                        <p v-else-if="!current" class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-center text-sm text-amber-900">
                            You don’t have a workplace assigned yet. Please ask your manager.
                        </p>

                        <div v-if="cameraStream" class="mt-6">
                            <video ref="video" autoplay playsinline class="aspect-video w-full rounded-2xl bg-slate-900 object-cover" aria-label="Camera preview"></video>
                            <p class="mt-2 text-center text-sm text-slate-600">Look at the camera while we verify it’s you.</p>
                        </div>

                        <p v-if="actionProgress" role="status" aria-live="polite" class="mt-3 text-center font-medium text-indigo-700">{{ actionProgress }}</p>
                        <div v-if="error" role="alert" class="mt-3 rounded-xl bg-rose-50 px-4 py-3 text-center text-sm text-rose-800">{{ error }}</div>
                        <div v-if="message" role="status" class="mt-3 rounded-xl bg-emerald-50 px-4 py-3 text-center text-sm font-medium text-emerald-800">{{ message }}</div>

                        <div v-if="!user.face_enrolled && current?.status !== 'completed'" class="mt-6">
                            <p class="text-center text-sm text-slate-600">Set up your face check with a guided camera photo.</p>
                            <button :disabled="busy" class="mt-3 min-h-14 w-full rounded-2xl bg-indigo-600 px-6 py-4 text-lg font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60" @click="openFaceSetup">
                                Set up my face check
                            </button>
                        </div>
                        <div v-else-if="nextAttendanceAction" class="mt-6 grid gap-3">
                            <button :disabled="busy || (!current && !workplaces.length)" class="min-h-16 w-full rounded-2xl bg-indigo-600 px-6 py-4 text-xl font-bold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60" @click="submitAttendance()">
                                {{ busy ? 'Please wait…' : nextAttendanceAction.label }}
                            </button>
                            <button v-if="current?.status === 'clocked_in'" :disabled="busy" class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60" @click="submitAttendance('break/start')">
                                Start a break
                            </button>
                        </div>
                        <p v-else class="mt-6 rounded-xl bg-slate-50 px-4 py-3 text-center text-sm text-slate-600">You’ve finished your attendance for today.</p>

                        <p class="mt-3 text-center text-xs leading-5 text-slate-500">
                            {{ user.face_enrolled ? 'We’ll check your face and location automatically. The time is recorded by the system.' : 'Your manager may need to finish setting up face verification first.' }}
                        </p>
                    </div>

                    <details class="mt-3 rounded-2xl bg-white px-5 py-4 ring-1 ring-slate-200">
                        <summary class="cursor-pointer font-semibold text-slate-700">More options</summary>
                        <div class="mt-5 border-t border-slate-100 pt-4">
                            <h3 class="font-semibold">Recent attendance</h3>
                            <div v-if="history.length" class="mt-3 divide-y divide-slate-100">
                                <div v-for="record in history.slice(0, 5)" :key="record.id" class="flex items-center justify-between gap-3 py-3 text-sm">
                                    <div><p class="font-medium">{{ new Date(record.work_date + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) }}</p><p class="mt-1 text-xs text-slate-500">{{ record.workplace?.name }}</p></div>
                                    <p class="text-right">{{ formatTime(record.clocked_in_at) }} – {{ formatTime(record.clocked_out_at) }}</p>
                                </div>
                            </div>
                            <p v-else class="mt-2 text-sm text-slate-500">Your attendance history will show here.</p>

                            <form class="mt-6 border-t border-slate-100 pt-5" @submit.prevent="requestCorrection">
                                <h3 class="font-semibold">Need to correct a day?</h3>
                                <p class="mt-1 text-sm text-slate-500">Send a request to your manager.</p>
                                <div class="mt-3 grid gap-3">
                                    <label class="text-sm font-medium">Date
                                        <input v-model="correctionForm.work_date" required type="date" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                                    </label>
                                    <label class="text-sm font-medium">Attendance to correct
                                        <select v-model="correctionForm.attendance_record_id" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                                            <option value="">Missing day</option>
                                            <option v-for="record in history" :key="record.id" :value="String(record.id)">{{ record.work_date }}</option>
                                        </select>
                                    </label>
                                    <label v-if="!correctionForm.attendance_record_id" class="text-sm font-medium">Workplace
                                        <select v-model="correctionForm.workplace_id" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                                            <option value="" disabled>Select workplace</option>
                                            <option v-for="place in workplaces" :key="place.id" :value="String(place.id)">{{ place.name }}</option>
                                        </select>
                                    </label>
                                    <label class="text-sm font-medium">What happened?
                                        <textarea v-model="correctionForm.reason" required maxlength="2000" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
                                    </label>
                                    <button :disabled="busy" class="min-h-11 rounded-lg border border-slate-300 px-4 py-2 font-semibold disabled:opacity-60">Send correction request</button>
                                </div>
                            </form>
                        </div>
                    </details>
                </section>

                <div v-else>
                <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-indigo-600">{{ isAdmin ? 'ADMIN CONSOLE' : 'EMPLOYEE PORTAL' }}</p>
                        <h1 class="mt-1 text-3xl font-semibold tracking-tight">Good day, {{ user.name.split(' ')[0] }}</h1>
                        <p class="mt-2 text-sm text-slate-500">Here’s your attendance at a glance.</p>
                    </div>
                    <button class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium hover:bg-slate-50" @click="refreshData">Refresh</button>
                </div>

                <div v-if="error" role="alert" class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ error }}</div>
                <div v-if="message" role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ message }}</div>

                <div v-if="isAdmin" class="mb-7 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-2xl bg-white p-5 ring-1 ring-slate-200"><div class="text-sm text-slate-500">Active employees</div><div class="mt-2 text-3xl font-semibold">{{ admin.dashboard?.employee_count ?? '—' }}</div></div>
                    <div class="rounded-2xl bg-white p-5 ring-1 ring-slate-200"><div class="text-sm text-slate-500">Working today</div><div class="mt-2 text-3xl font-semibold">{{ admin.dashboard?.clocked_in_count ?? '—' }}</div></div>
                    <div class="rounded-2xl bg-white p-5 ring-1 ring-slate-200"><div class="text-sm text-slate-500">Pending corrections</div><div class="mt-2 text-3xl font-semibold">{{ admin.dashboard?.pending_corrections ?? '—' }}</div></div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[1.4fr_0.8fr]">
                    <section class="rounded-2xl bg-white p-6 ring-1 ring-slate-200">
                        <div class="flex items-start justify-between gap-3">
                            <div><p class="text-sm font-medium text-slate-500">TODAY’S ATTENDANCE</p><h2 class="mt-1 text-xl font-semibold">{{ current?.status === 'completed' ? 'Day complete' : current?.status === 'on_break' ? 'On a break' : current ? 'You’re clocked in' : 'Ready when you are' }}</h2></div>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold capitalize text-emerald-700">{{ current?.status?.replace('_', ' ') || 'Not started' }}</span>
                        </div>
                        <div class="my-7 grid grid-cols-2 gap-4 rounded-xl bg-slate-50 p-4">
                            <div><p class="text-xs text-slate-500">Clock in</p><p class="mt-1 text-lg font-semibold">{{ formatTime(current?.clocked_in_at) }}</p></div>
                            <div><p class="text-xs text-slate-500">Clock out</p><p class="mt-1 text-lg font-semibold">{{ formatTime(current?.clocked_out_at) }}</p></div>
                        </div>
                        <label v-if="!current" class="mb-5 block text-sm font-medium">Assigned workplace
                            <select v-model="selectedWorkplace" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-3">
                                <option value="" disabled>Select a workplace</option>
                                <option v-for="place in workplaces" :key="place.id" :value="String(place.id)">{{ place.name }}</option>
                            </select>
                        </label>
                        <div class="rounded-xl border border-dashed border-slate-300 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div><p class="font-medium">Identity verification</p><p class="mt-1 text-xs text-slate-500">{{ user.face_enrolled ? 'Face profile enrolled' : 'Enroll your face profile to clock in' }}</p></div>
                                <button v-if="!user.face_enrolled" type="button" class="min-h-11 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" @click="openFaceSetup">Set up face check</button>
                            </div>
                        </div>
                        <div class="mt-5 flex flex-wrap gap-3">
                            <button v-if="!current" :disabled="busy" class="rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" @click="submitAttendance('clock-in')">Clock in</button>
                            <template v-else-if="current.status !== 'completed'">
                                <button v-if="current.status === 'clocked_in'" :disabled="busy" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold hover:bg-slate-50 disabled:opacity-50" @click="submitAttendance('break/start')">Start break</button>
                                <button v-else-if="current.status === 'on_break'" :disabled="busy" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold hover:bg-slate-50 disabled:opacity-50" @click="submitAttendance('break/end')">End break</button>
                                <button v-if="current.status === 'clocked_in'" :disabled="busy" class="rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" @click="submitAttendance('clock-out')">Clock out</button>
                            </template>
                            <p v-if="busy" class="self-center text-sm text-slate-500">Saving securely…</p>
                        </div>
                        <p class="mt-4 text-xs leading-5 text-slate-500">Every attendance action requires a live location and a server-verified face match. The server records the authoritative time.</p>
                    </section>

                    <section class="rounded-2xl bg-white p-6 ring-1 ring-slate-200">
                        <div class="flex items-center justify-between"><div><p class="text-sm font-medium text-slate-500">RECENT HISTORY</p><h2 class="mt-1 text-xl font-semibold">Attendance</h2></div><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600">{{ history.length }} records</span></div>
                        <div v-if="history.length" class="mt-5 divide-y divide-slate-100">
                            <div v-for="record in history.slice(0, 6)" :key="record.id" class="flex items-center justify-between gap-4 py-3">
                                <div><p class="font-medium">{{ new Date(record.work_date + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) }}</p><p class="mt-1 text-xs text-slate-500">{{ record.workplace?.name }}</p></div>
                                <div class="text-right"><p class="text-sm font-medium">{{ formatTime(record.clocked_in_at) }} – {{ formatTime(record.clocked_out_at) }}</p><p class="mt-1 text-xs capitalize text-slate-500">{{ record.status.replace('_', ' ') }}</p></div>
                            </div>
                        </div>
                        <div v-else class="mt-6 rounded-xl bg-slate-50 p-6 text-center text-sm text-slate-500">Your completed workdays will appear here.</div>
                    </section>
                </div>

                <section v-if="!isAdmin" class="mt-6 rounded-2xl bg-white p-6 ring-1 ring-slate-200">
                    <h2 class="text-xl font-semibold">Request an attendance correction</h2>
                    <p class="mt-1 text-sm text-slate-500">An administrator will review and approve any changes.</p>
                    <form class="mt-5 grid gap-3 md:grid-cols-2" @submit.prevent="requestCorrection">
                        <label class="text-sm font-medium">Work date
                            <input v-model="correctionForm.work_date" required type="date" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        </label>
                        <label class="text-sm font-medium">Existing attendance record (optional)
                            <select v-model="correctionForm.attendance_record_id" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                                <option value="">Missing attendance record</option>
                                <option v-for="record in history" :key="record.id" :value="String(record.id)">{{ record.work_date }} · {{ record.workplace?.name }}</option>
                            </select>
                        </label>
                        <label v-if="!correctionForm.attendance_record_id" class="text-sm font-medium">Workplace
                            <select v-model="correctionForm.workplace_id" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                                <option value="" disabled>Select an assigned workplace</option>
                                <option v-for="place in workplaces" :key="place.id" :value="String(place.id)">{{ place.name }}</option>
                            </select>
                        </label>
                        <label class="text-sm font-medium">Requested clock-in
                            <input v-model="correctionForm.clocked_in_at" type="datetime-local" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        </label>
                        <label class="text-sm font-medium">Requested clock-out
                            <input v-model="correctionForm.clocked_out_at" type="datetime-local" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                        </label>
                        <label class="text-sm font-medium md:col-span-2">Reason
                            <textarea v-model="correctionForm.reason" required maxlength="2000" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
                        </label>
                        <button :disabled="busy" class="justify-self-start rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Submit request</button>
                    </form>
                </section>

                <section v-if="isAdmin" class="mt-6 grid gap-6 lg:grid-cols-2">
                    <div class="rounded-2xl bg-white p-6 ring-1 ring-slate-200">
                        <h2 class="text-xl font-semibold">Employee management</h2>
                        <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="createEmployee">
                            <input v-model="newEmployee.name" required placeholder="Employee name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <input v-model="newEmployee.email" required type="email" placeholder="Email" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <input v-model="newEmployee.password" required type="password" minlength="12" placeholder="Temporary password (12+ chars)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm sm:col-span-2">
                            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white sm:col-span-2">Add employee</button>
                        </form>
                        <div class="mt-5 max-h-56 divide-y divide-slate-100 overflow-auto">
                            <div v-for="employee in admin.employees" :key="employee.id" class="flex items-center justify-between py-3 text-sm"><div><p class="font-medium">{{ employee.name }}</p><p class="text-xs text-slate-500">{{ employee.email }}</p></div><span class="text-xs text-slate-500">{{ employee.face_enrolled ? 'Enrolled' : 'Not enrolled' }}</span></div>
                        </div>
                        <form class="mt-5 grid gap-3 border-t border-slate-100 pt-5 sm:grid-cols-3" @submit.prevent="assignWorkplace">
                            <select v-model="assignment.user_id" required class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="" disabled>Employee</option><option v-for="employee in admin.employees" :key="employee.id" :value="String(employee.id)">{{ employee.name }}</option></select>
                            <select v-model="assignment.workplace_id" required class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="" disabled>Workplace</option><option v-for="place in admin.workplaces" :key="place.id" :value="String(place.id)">{{ place.name }}</option></select>
                            <select v-model="assignment.shift_id" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="">No shift</option><option v-for="shift in admin.shifts" :key="shift.id" :value="String(shift.id)">{{ shift.name }}</option></select>
                            <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold sm:col-span-3">Assign workplace and shift</button>
                        </form>
                    </div>
                    <div class="rounded-2xl bg-white p-6 ring-1 ring-slate-200">
                        <h2 class="text-xl font-semibold">Workplace setup</h2>
                        <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="createWorkplace">
                            <input v-model="newWorkplace.name" required placeholder="Workplace name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <input v-model="newWorkplace.address" placeholder="Address" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <input v-model="newWorkplace.latitude" required type="number" step="any" placeholder="Latitude" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <input v-model="newWorkplace.longitude" required type="number" step="any" placeholder="Longitude" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <input v-model="newWorkplace.radius_meters" required type="number" min="25" max="5000" placeholder="Radius in metres" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Add workplace</button>
                        </form>
                        <form class="mt-5 grid gap-3 border-t border-slate-100 pt-5 sm:grid-cols-2" @submit.prevent="createShift">
                            <input v-model="newShift.name" required placeholder="Shift name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <input v-model="newShift.break_minutes" required type="number" min="0" max="480" placeholder="Break minutes" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <label class="text-xs text-slate-500">Starts<input v-model="newShift.starts_at" required type="time" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900"></label>
                            <label class="text-xs text-slate-500">Ends<input v-model="newShift.ends_at" required type="time" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900"></label>
                            <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold sm:col-span-2">Add shift</button>
                        </form>
                        <div v-if="admin.corrections.length" class="mt-7">
                            <h3 class="font-semibold">Correction requests <span class="text-sm font-normal text-slate-500">({{ admin.corrections.length }})</span></h3>
                            <div v-for="correction in admin.corrections" :key="correction.id" class="mt-3 rounded-xl bg-slate-50 p-4">
                                <p class="text-sm font-semibold">{{ correction.employee?.name }} · {{ correction.work_date }}</p><p class="mt-1 text-sm text-slate-600">{{ correction.reason }}</p>
                                <div class="mt-3 flex gap-2"><button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white" @click="reviewCorrection(correction.id, 'approved')">Approve</button><button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold" @click="reviewCorrection(correction.id, 'rejected')">Reject</button></div>
                            </div>
                        </div>
                    </div>
                </section>
                </div>
            </template>
            <div v-if="faceSetupOpen" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/70 p-4" @keydown.esc="closeFaceSetup">
                <section role="dialog" aria-modal="true" aria-labelledby="face-setup-title" class="my-auto w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-7">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
                                {{ faceSetupStep === 'intro' ? 'Step 1 of 3' : faceSetupStep === 'capture' ? 'Step 2 of 3' : 'Step 3 of 3' }}
                            </p>
                            <h2 id="face-setup-title" class="mt-1 text-lg font-bold text-slate-900">
                                {{ faceSetupStep === 'intro' ? 'Set up your face check' : faceSetupStep === 'capture' ? 'Center your face' : 'Review your photo' }}
                            </h2>
                        </div>
                        <button type="button" aria-label="Close face setup" :disabled="busy" class="grid size-11 place-items-center rounded-full text-2xl text-slate-500 hover:bg-slate-100 disabled:opacity-50" @click="closeFaceSetup">×</button>
                    </div>

                    <div class="p-5 sm:p-7">
                        <template v-if="faceSetupStep === 'intro'">
                            <div class="mx-auto grid size-20 place-items-center rounded-full bg-indigo-50 text-indigo-600">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="size-10" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V5a1 1 0 0 1 1-1h3m8 0h3a1 1 0 0 1 1 1v3m0 8v3a1 1 0 0 1-1 1h-3m-8 0H5a1 1 0 0 1-1-1v-3M9 10h.01M15 10h.01M9 15c.8.7 1.8 1 3 1s2.2-.3 3-1" />
                                </svg>
                            </div>
                            <h3 class="mt-5 text-center text-xl font-bold text-slate-900">A quick camera setup</h3>
                            <p class="mt-2 text-center leading-6 text-slate-600">We’ll take one clear photo for your attendance face check. Find a bright spot, remove sunglasses or anything covering your face, and look straight at the camera.</p>
                            <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-2xl bg-slate-50 p-4 text-sm leading-5 text-slate-700">
                                <input v-model="faceConsent" type="checkbox" class="mt-0.5 size-5 shrink-0 rounded border-slate-300 accent-indigo-600">
                                <span>I agree to use this photo for attendance identity verification.</span>
                            </label>
                            <p class="mt-4 text-xs leading-5 text-slate-500">Your photo is sent securely to the configured face verification provider. Nghena does not store raw camera photos. This camera guide is not Apple Face ID and does not track head movement.</p>
                        </template>

                        <template v-else-if="faceSetupStep === 'capture'">
                            <p class="mb-4 text-center text-sm leading-5 text-slate-600">Keep your face inside the guide and make sure the light is in front of you.</p>
                            <div class="relative overflow-hidden rounded-3xl bg-slate-950">
                                <video ref="enrollmentVideo" autoplay playsinline muted class="aspect-[4/5] w-full object-cover [transform:scaleX(-1)]" aria-label="Live face setup camera preview"></video>
                                <div class="pointer-events-none absolute inset-y-[8%] left-[19%] right-[19%] rounded-[50%] border-2 border-dashed border-white shadow-[0_0_0_999px_rgba(15,23,42,0.24)]" aria-hidden="true"></div>
                                <div class="pointer-events-none absolute inset-x-0 bottom-3 text-center text-sm font-medium text-white drop-shadow">Fit your face inside the outline</div>
                            </div>
                        </template>

                        <template v-else>
                            <img :src="enrollmentPreviewUrl" alt="Preview of your face setup photo" class="aspect-[4/5] w-full rounded-3xl bg-slate-100 object-cover">
                            <p class="mt-4 text-center text-sm leading-5 text-slate-600">Check that your face is clear and well lit. Retake it if you’re not happy with the photo.</p>
                        </template>

                        <p v-if="error" role="alert" class="mt-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ error }}</p>

                        <div class="mt-6 grid gap-3 sm:grid-cols-2">
                            <button v-if="faceSetupStep === 'intro'" type="button" :disabled="busy" class="min-h-12 rounded-xl border border-slate-300 px-4 py-3 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="closeFaceSetup">Not now</button>
                            <button v-else-if="faceSetupStep === 'capture'" type="button" :disabled="busy" class="min-h-12 rounded-xl border border-slate-300 px-4 py-3 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="closeFaceSetup">Cancel</button>
                            <button v-else type="button" class="min-h-12 rounded-xl border border-slate-300 px-4 py-3 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" :disabled="busy" @click="retakeEnrollmentPhoto">Retake photo</button>

                            <button v-if="faceSetupStep === 'intro'" type="button" :disabled="!faceConsent || busy" class="min-h-12 rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50" @click="startEnrollmentCamera">Continue to camera</button>
                            <button v-else-if="faceSetupStep === 'capture'" type="button" :disabled="busy" class="min-h-12 rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-60" @click="captureEnrollmentPhoto">
                                {{ busy ? 'Preparing camera…' : 'Take photo' }}
                            </button>
                            <button v-else type="button" :disabled="busy" class="min-h-12 rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-60" @click="submitFaceEnrollment">
                                {{ busy ? 'Saving securely…' : 'Use this photo' }}
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </main>
        <footer class="mx-auto max-w-7xl px-5 pb-8 text-xs text-slate-400">Nghena · Secure workforce attendance</footer>
    </div>
</template>

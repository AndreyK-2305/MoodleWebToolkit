import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import type { MoodleCandidate } from '@/components/remote-access-panel';
import RemoteCollectionForm from '@/components/remote-collection-form';
import { remoteServer } from '@/test/remote-server';

// Inertia outside a page: an administrator, and forms that keep their initial data.
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { role: 'ADMIN' } } } }),
    useForm: <T extends object>(data: T) => ({
        data,
        errors: {},
        processing: false,
        setData: () => {},
        transform: () => {},
        post: () => {},
        put: () => {},
    }),
    router: { post: () => {}, reload: () => {} },
}));

const candidate: MoodleCandidate = {
    config_path: '/var/www/html/config.php',
    runtime: 'compose',
    compose_dir: '/opt/moodle-lab/runtime',
    compose_service: 'php',
    compose_profile: 'tools',
    run_as: 'www-data',
    config_owner: 'www-data',
    issue: null,
    too_long: false,
};

describe('RemoteCollectionForm', () => {
    it('keeps the profile creation out of the collection form', () => {
        const html = renderToStaticMarkup(
            <RemoteCollectionForm
                projectUuid="p"
                options={{}}
                agentVersion="1.17.0"
                editable
                servers={[
                    remoteServer({
                        uuid: 'a',
                        moodle_detected: {
                            detected_at: 'x',
                            error_code: null,
                            php_present: false,
                            docker_checked: true,
                            candidates: [candidate],
                        },
                    }),
                ]}
            />,
        );

        // One <form>, the collection's: a nested one would submit and save the
        // collection when «Crear y comprobar el perfil» is pressed.
        expect(html.match(/<form/g)).toHaveLength(1);
        expect(html).toContain('Crear y comprobar el perfil');
        const create = html.match(
            /<button[^>]*>(?:(?!<\/button>).)*Crear y comprobar el perfil/,
        )?.[0];
        expect(create).toContain('type="button"');
        // The Compose candidate prefills its directory, service and profile.
        expect(html).toContain('value="/opt/moodle-lab/runtime"');
        expect(html).toContain('value="tools"');
    });
});

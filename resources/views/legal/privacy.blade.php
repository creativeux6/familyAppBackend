<x-legal-layout title="Privacy Policy" :app-name="$appName" :app-url="$appUrl">
    <h1>Privacy Policy</h1>
    <p>Last updated: 27 August 2026</p>
    <p>{{ $appName }} is a family vault made by Prolampx. This policy describes the information we collect when you use the Android app and {{ $appUrl }}.</p>

    <h2>Account information</h2>
    <p>We collect your <strong>phone number</strong> and password to create and sign in to your account. You may also add a display name and optional profile details (for example date of birth or birthplace) that you type yourself.</p>

    <h2>Contacts (optional)</h2>
    <p>If you tap Contacts, {{ $appName }} can read your phone book to find relatives who already have an account. Phone numbers are hashed on your device before they are sent to our servers. We do not upload your full address book. You can refuse this permission and still use the app.</p>

    <h2>Photos, videos, and files you pick</h2>
    <p>When you upload, you choose specific photos, videos, or files through the system picker. We do not scan your gallery in the background. Files are encrypted before they leave your device and are stored in object storage (Backblaze B2).</p>

    <h2>Voice messages</h2>
    <p>If you record a chat voice note, the microphone is used only while you hold the record control. Audio is encrypted like other media.</p>

    <h2>Push notifications</h2>
    <p>With your permission we store a Firebase Cloud Messaging (FCM) device token so we can deliver chat, connection, and sharing alerts. We do not sell this token.</p>

    <h2>Subscriptions</h2>
    <p>Paid storage plans on Android are billed by Google Play. We receive a purchase token from Google so we can unlock the plan you bought. We do not receive your full payment card number.</p>

    <h2>Family tree</h2>
    <p>Relatives you add to a family tree may be visible to connected family members. If you delete your account we unlink you from those shared tree nodes; we do not erase other people’s family records.</p>

    <h2>What we do not do</h2>
    <ul>
        <li>We do not show ads in {{ $appName }}.</li>
        <li>We do not use your data for advertising.</li>
        <li>We do not sell personal information.</li>
    </ul>

    <h2>Retention and deletion</h2>
    <p>You can delete your account in the app (Profile → Delete account) or on our <a href="{{ route('legal.account-deletion') }}">account deletion page</a>. Deletion removes your login, tokens, personal media, and profile. Shared family-tree stubs stay so relatives keep their tree.</p>

    <h2>Contact</h2>
    <p>Privacy questions: <a href="mailto:privacy@prolampx.com">privacy@prolampx.com</a></p>
</x-legal-layout>

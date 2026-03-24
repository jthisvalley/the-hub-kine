<x-mail::message>
    # Réinitialisation de votre mot de passe

    Bonjour,

    Vous avez demandé la réinitialisation de votre mot de passe pour votre compte Le Hub Kiné.

    Cliquez sur le bouton ci-dessous pour définir un nouveau mot de passe :

    <x-mail::button :url="$resetUrl">
        Réinitialiser mon mot de passe
    </x-mail::button>

    Ce lien de réinitialisation expirera dans 60 minutes.

    Si vous n'avez pas demandé de réinitialisation de mot de passe, veuillez ignorer cet email.

    Cordialement,<br>
    L'équipe Le Hub Kiné
</x-mail::message>

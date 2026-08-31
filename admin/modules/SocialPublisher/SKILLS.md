# SocialPublisher — Skills Reference

## Overview
Social media posting module for publishing image ads and promotional content to Facebook Pages and Twitter/X. Includes AI-powered content and image generation, provider credential management, and post history.

## Capabilities
- Publish to Facebook Pages
- Publish to Twitter/X
- AI-powered content generation
- AI-powered image generation
- Image upload for posts
- Post composition and preview
- Provider credential management
- Post history tracking

## API Endpoints
- `action=list_providers` — List social media providers
- `action=save_provider` — Save provider credentials
- `action=delete_provider` — Delete a provider
- `action=test_provider` — Test provider connectivity
- `action=compose_post` — Compose a social media post
- `action=generate_content` — AI-generate post content
- `action=generate_image` — AI-generate post image
- `action=upload_image` — Upload image for post
- `action=list_posts` — List post history

## Data Storage
- `admin/data/SocialPublisher/` — Provider configs
- `admin/data/SocialPublisher/posts/` — Post history

## Dependencies
- AIResources (for AI content/image generation)

## Common Tasks
1. **Connect Facebook Page**: Add Page access token and Page ID
2. **Compose and post**: Write content, attach image, select provider, publish
3. **AI-generate content**: Use generate_content for AI-written post copy
4. **AI-generate image**: Use generate_image for promotional graphics
5. **View post history**: Browse previously published posts

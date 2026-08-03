# Build multi-stage : compile le SPA Vite/React puis le sert avec nginx.
#
# VITE_API_BASE_URL est un ARG de build (pas une variable d'environnement de
# conteneur) car Vite l'inscrit en dur dans le JS compilé — c'est une
# contrainte de Vite, pas de ce Dockerfile. Par défaut "/api" : le nginx de
# l'étage final reverse-proxy /api/* vers le conteneur backend (voir
# nginx.conf), donc le SPA appelle une URL relative à son propre domaine —
# ni CORS à gérer, ni URL de backend à connaître à l'avance. Pour pointer
# vers un backend différent, reconstruire l'image avec
# `docker compose build --build-arg VITE_API_BASE_URL=https://...`.
FROM node:20-alpine AS build
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

ARG VITE_API_BASE_URL=/api
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
RUN npm run build

FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]

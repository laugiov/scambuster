FROM node:20-alpine

WORKDIR /app

# Install dependencies first (layer caching)
COPY frontend-react/package*.json ./
RUN npm ci

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
